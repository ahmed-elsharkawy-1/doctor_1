<?php

namespace App\Services\V1\Patients;

use App\Enums\BookingStatus;
use App\Enums\OnlineBookingBlockReason;
use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Whether a patient may book on the public page.
 *
 * A patient with `clinic.self_booking.no_show_block.threshold` no-shows inside
 * the last `window_days` days is blocked from it, at a clinic with the rule
 * switched on. Every no-show counts, whoever made the booking. The clinic can
 * lift a block — only no-shows marked after that count again — or block by
 * hand, which works whether the rule is on or not.
 *
 * Only the public page reads this. The clinic books a blocked patient by
 * phone exactly as it books anybody.
 */
class OnlineBookingService
{
    public function isBlocked(Patient $patient): bool
    {
        return $patient->online_booking_blocked_at !== null;
    }

    /**
     * The no-shows the rule is counting right now: inside the window, and
     * marked after the clinic last lifted a block.
     */
    public function countedNoShows(Patient $patient): int
    {
        $clinic = $patient->clinic;
        $since = $clinic->currentDay()->subDays($this->windowDays())->toDateString();

        return $patient->bookings()
            ->where('status', BookingStatus::NO_SHOW)
            ->whereDate('visit_date', '>=', $since)
            ->when(
                $patient->online_booking_allowed_at !== null,
                fn ($query) => $query->where('cancelled_at', '>', $patient->online_booking_allowed_at),
            )
            ->count();
    }

    /** The most recent no-show, counted or not — for the clinic's eyes. */
    public function lastNoShowDate(Patient $patient): ?Carbon
    {
        $date = $patient->bookings()
            ->where('status', BookingStatus::NO_SHOW)
            ->max('visit_date');

        return $date === null ? null : Carbon::parse($date);
    }

    /**
     * Run after a patient has been marked no-show. Blocks them when that
     * brings them to the threshold.
     */
    public function checkAfterNoShow(Patient $patient): bool
    {
        if ($this->isBlocked($patient) || ! $patient->clinic->no_show_block_enabled) {
            return false;
        }

        if ($this->countedNoShows($patient) < $this->threshold()) {
            return false;
        }

        $this->stamp($patient, OnlineBookingBlockReason::NO_SHOWS);

        return true;
    }

    /**
     * The same check for several patients of one clinic at once — the day
     * closing on its own marks them in bulk.
     *
     * @param  iterable<int>  $patientIds
     */
    public function checkPatients(Clinic $clinic, iterable $patientIds): int
    {
        if (! $clinic->no_show_block_enabled) {
            return 0;
        }

        return $clinic->patients()
            ->whereIn('id', collect($patientIds)->unique()->values())
            ->get()
            ->filter(fn (Patient $patient) => $this->checkAfterNoShow($patient->setRelation('clinic', $clinic)))
            ->count();
    }

    /**
     * Patients of this clinic the rule would block today and has not yet.
     *
     * @return Collection<int, Patient>
     */
    public function qualifying(Clinic $clinic): Collection
    {
        $since = $clinic->currentDay()->subDays($this->windowDays())->toDateString();

        $ids = $clinic->bookings()
            ->where('status', BookingStatus::NO_SHOW)
            ->whereDate('visit_date', '>=', $since)
            ->whereNotNull('patient_id')
            ->groupBy('patient_id')
            ->havingRaw('count(*) >= ?', [$this->threshold()])
            ->pluck('patient_id');

        return $clinic->patients()
            ->whereIn('id', $ids)
            ->whereNull('online_booking_blocked_at')
            ->orderBy('id')
            ->get()
            ->each(fn (Patient $patient) => $patient->setRelation('clinic', $clinic))
            // The lift is per patient, so the count is asked again for each.
            ->filter(fn (Patient $patient) => $this->countedNoShows($patient) >= $this->threshold())
            ->values();
    }

    /** The clinic blocks a patient by hand. */
    public function block(Patient $patient): Patient
    {
        if (! $this->isBlocked($patient)) {
            $this->stamp($patient, OnlineBookingBlockReason::MANUAL);
        }

        return $patient;
    }

    /** The clinic lifts a block. No-shows before this moment stop counting. */
    public function allow(Patient $patient): Patient
    {
        $patient->forceFill([
            'online_booking_blocked_at' => null,
            'online_booking_block_reason' => null,
            'online_booking_allowed_at' => $this->now($patient),
        ])->save();

        return $patient;
    }

    public function threshold(): int
    {
        return max(1, (int) config('clinic.self_booking.no_show_block.threshold'));
    }

    public function windowDays(): int
    {
        return max(1, (int) config('clinic.self_booking.no_show_block.window_days'));
    }

    private function stamp(Patient $patient, OnlineBookingBlockReason $reason): void
    {
        $patient->forceFill([
            'online_booking_blocked_at' => $this->now($patient),
            'online_booking_block_reason' => $reason,
        ])->save();
    }

    /**
     * The clinic's wall clock, the way booking stamps (`cancelled_at`) are
     * stored — so "marked after the lift" compares like with like.
     */
    private function now(Patient $patient): Carbon
    {
        return Carbon::now($patient->clinic?->timezone ?? config('app.timezone'));
    }
}
