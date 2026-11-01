<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Clinic;
use App\Services\V1\Booking\SlotHoldService;
use App\Services\V1\Patients\OnlineBookingService;
use App\Services\V1\Patients\PhoneVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Closes out days that have finished — SPEC §5.4.
 *
 * Without this, a patient booked at 09:00 who never turned up is still
 * "not finished" at 6pm and keeps appearing in the postpone list. The
 * secretary should not have to tidy up by hand.
 *
 * Runs hourly and is idempotent: each clinic is closed out only once its own
 * local day has rolled over, which is what makes this correct for clinics in
 * different timezones. A day whose period runs past midnight rolls over when
 * that period ends, not at midnight — its patients are still being seen.
 */
class CloseClinicDayCommand extends Command
{
    protected $signature = 'clinic:close-day
                            {--clinic= : Limit to one clinic id}';

    protected $description = 'Cancel leftover bookings from days that have already ended';

    public function handle(): int
    {
        $clinics = Clinic::query()
            ->when($this->option('clinic'), fn ($query, $id) => $query->whereKey($id))
            ->where('is_active', true)
            ->get();

        $closed = 0;

        foreach ($clinics as $clinic) {
            $closed += $this->closeFor($clinic);
        }

        // Housekeeping, not correctness: a lapsed hold stops blocking its slot
        // the moment it lapses, because availability filters on `expires_at`.
        // This only stops the table growing for ever.
        $released = app(SlotHoldService::class)->purgeExpired();

        // A verification row holds a phone number with no patient attached —
        // the only place in the system that is true — so it goes once it is
        // past the window the hourly caps count.
        $codes = app(PhoneVerificationService::class)->purgeStale();

        $this->info("Closed {$closed} leftover booking(s) across {$clinics->count()} clinic(s).");
        $this->info("Cleared {$released} expired slot hold(s) and {$codes} stale verification(s).");

        return self::SUCCESS;
    }

    private function closeFor(Clinic $clinic): int
    {
        $now = Carbon::now($clinic->timezone);

        // A day stays open while either says so: its own overnight period
        // has not closed yet, or it is not yet the closing hour — a doctor
        // running late past a 23:59 shift is still seeing that day's list.
        $today = min($clinic->currentDay()->toDateString(), $this->clinicDay($now));

        // A delay can push a patient's real time past the closing hour. One
        // whose visit has not even started is not a no-show: it waits until
        // its time has passed. Times are stored as the clinic's wall clock.
        $started = fn ($query) => $query->whereNull('start_at')
            ->orWhere('start_at', '<=', $now->format('Y-m-d H:i:s'));

        // Never touched the clinic: she never turned up.
        $untouched = $clinic->bookings()
            ->whereDate('visit_date', '<', $today)
            ->where('status', BookingStatus::BOOKED)
            ->where($started);

        // Read before the bulk update, which fires no model events: these are
        // the patients the no-show rule has to look at again.
        $noShowPatients = (clone $untouched)->whereNotNull('patient_id')->pluck('patient_id');

        $noShows = $untouched
            ->update([
                'status' => BookingStatus::NO_SHOW,
                'cancel_reason' => null,
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);

        // Arrived, or went in, but the visit was never completed — a data-entry
        // lapse rather than a no-show. Kept out of revenue either way.
        $incomplete = $clinic->bookings()
            ->whereDate('visit_date', '<', $today)
            ->whereIn('status', [BookingStatus::ARRIVED, BookingStatus::WITH_DOCTOR])
            ->where($started)
            ->update([
                'status' => BookingStatus::CANCELLED,
                'cancel_reason' => CancelReason::INCOMPLETE,
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);

        app(OnlineBookingService::class)->checkPatients($clinic, $noShowPatients);

        return $noShows + $incomplete;
    }

    /**
     * The clinic day `now` falls in. Before the closing hour it is still the
     * day before — an evening shift that runs past midnight has not ended —
     * so only days before that one are closed.
     */
    private function clinicDay(Carbon $now): string
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config('clinic.end_of_day.closes_at')));

        return $now->copy()->subHours($hour)->subMinutes($minute)->toDateString();
    }
}
