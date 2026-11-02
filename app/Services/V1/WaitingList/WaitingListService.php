<?php

namespace App\Services\V1\WaitingList;

use App\Enums\ApiErrorCode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\WaitingListStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitType;
use App\Models\WaitingListEntry;
use App\Services\V1\Booking\ClinicDayLock;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every rule of the waiting list for a full day.
 *
 * A patient on the list holds nothing. If a slot opens the secretary calls
 * them and books it as any other booking — BookingService then takes them
 * off the list (settleForBooking) and sends "booked from the waiting list"
 * instead of the usual confirmation. When the day's last period ends, whoever
 * is still on it is told no slot opened (closeEndedDays).
 *
 * The public page, the clinic web app and the mobile API all come through
 * here, so the cap, the duplicate check and the "day is over" rule are asked
 * the same way everywhere.
 */
class WaitingListService
{
    public function __construct(
        private readonly ClinicDayLock $lock,
        private readonly SlotAvailabilityService $slots,
        private readonly WhatsAppMessagingService $messaging,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Who is on a day's list, first to join first.
     *
     * @return Collection<int, WaitingListEntry>
     */
    public function forDay(Clinic $clinic, string $date): Collection
    {
        return $clinic->waitingListEntries()
            ->with(['patient', 'visitType'])
            ->waiting()
            ->onDate($date)
            ->orderBy('id')
            ->get();
    }

    public function countOn(Clinic $clinic, string $date): int
    {
        return $clinic->waitingListEntries()->waiting()->onDate($date)->count();
    }

    /**
     * Everyone on a list for today or later — the clinic app's badge.
     */
    public function upcomingCount(Clinic $clinic): int
    {
        return $clinic->waitingListEntries()
            ->waiting()
            ->whereDate('visit_date', '>=', $clinic->currentDay()->toDateString())
            ->count();
    }

    /**
     * 1 for the first to join. Counts only those still waiting, so a patient
     * moves up as the people ahead of them are booked or leave.
     */
    public function position(WaitingListEntry $entry): int
    {
        return WaitingListEntry::query()
            ->where('clinic_id', $entry->clinic_id)
            ->waiting()
            ->onDate($entry->visit_date->toDateString())
            ->where('id', '<=', $entry->id)
            ->count();
    }

    public function isFull(Clinic $clinic, string $date): bool
    {
        return $this->countOn($clinic, $date) >= $clinic->waitingListMaxPerDay();
    }

    /** This patient's place on that day's list, if they have one. */
    public function entryFor(Clinic $clinic, Patient $patient, string $date): ?WaitingListEntry
    {
        return $clinic->waitingListEntries()
            ->with(['patient', 'visitType'])
            ->waiting()
            ->where('patient_id', $patient->id)
            ->onDate($date)
            ->first();
    }

    public function find(Clinic $clinic, int $entryId): WaitingListEntry
    {
        $entry = $clinic->waitingListEntries()
            ->with(['patient', 'visitType'])
            ->waiting()
            ->whereKey($entryId)
            ->first();

        if ($entry === null) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_ENTRY_NOT_FOUND,
                __('waiting_list.not_found'),
                http: 404,
            );
        }

        return $entry;
    }

    /**
     * When the day's last period closes, as an instant — or null when the
     * clinic does not open that day at all.
     */
    public function dayEndsAt(Clinic $clinic, Carbon $date): ?Carbon
    {
        if ($this->slots->isHoliday($clinic, $date)) {
            return null;
        }

        $schedule = $clinic->scheduleFor(DayOfWeek::fromDate($date));

        if ($schedule === null || ! $schedule->is_open) {
            return null;
        }

        $end = null;

        foreach ($schedule->periods as $period) {
            [, $closes] = $period->boundsOn($date, $clinic->timezone);
            $end = $end === null || $closes->gt($end) ? $closes : $end;
        }

        return $end;
    }

    /**
     * Whether a list for this day can still be joined: the clinic opens that
     * day, it is inside the booking window, and its last period has not
     * ended yet.
     */
    public function isJoinable(Clinic $clinic, string $date): bool
    {
        $day = Carbon::parse($date, $clinic->timezone)->startOfDay();

        if (! $this->slots->isWithinWindow($clinic, $day)) {
            return false;
        }

        $end = $this->dayEndsAt($clinic, $day);

        return $end !== null && Carbon::now($clinic->timezone)->lt($end);
    }

    /*
    |--------------------------------------------------------------------------
    | Joining and leaving
    |--------------------------------------------------------------------------
    */

    /**
     * Puts a patient on a day's list and tells them so.
     *
     * Already on it: their place is handed back, with no second message.
     * Already booked that day: refused — there is nothing to wait for.
     *
     * The count and the write happen under the same lock bookings take for
     * the day, so two patients joining at once cannot both take the last
     * place.
     */
    public function join(
        Clinic $clinic,
        Patient $patient,
        ?VisitType $visitType,
        string $date,
        BookingSource $source,
        ?User $actor = null,
    ): WaitingListEntry {
        $this->assertEnabled($clinic);

        $day = Carbon::parse($date, $clinic->timezone)->startOfDay();
        $date = $day->toDateString();

        if (! $this->isJoinable($clinic, $date)) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_CLOSED,
                __('waiting_list.closed'),
                http: 409,
            );
        }

        $joined = false;

        $entry = $this->lock->claim($clinic, $day, function () use (
            $clinic, $patient, $visitType, $date, $source, $actor, &$joined
        ): WaitingListEntry {
            $existing = $this->entryFor($clinic, $patient, $date);

            if ($existing !== null) {
                return $existing;
            }

            $booked = $patient->bookings()
                ->where('clinic_id', $clinic->id)
                ->onDate($date)
                ->whereIn('status', BookingStatus::pending())
                ->exists();

            if ($booked) {
                throw ApiException::make(
                    ApiErrorCode::WAITING_LIST_ALREADY_BOOKED,
                    __('waiting_list.already_booked'),
                    http: 409,
                );
            }

            if ($this->isFull($clinic, $date)) {
                throw ApiException::make(
                    ApiErrorCode::WAITING_LIST_FULL,
                    __('waiting_list.full'),
                    details: ['max_per_day' => $clinic->waitingListMaxPerDay()],
                    http: 409,
                );
            }

            $joined = true;

            return $clinic->waitingListEntries()->create([
                'patient_id' => $patient->id,
                'visit_type_id' => $visitType?->id,
                'visit_date' => $date,
                'status' => WaitingListStatus::WAITING,
                'source' => $source,
                'created_by' => $actor?->id,
            ]);
        });

        // Outside the lock, like the booking confirmation: a queued message
        // must never outlive a rolled-back write.
        if ($joined) {
            $this->messaging->sendWaitingListJoined($clinic, $entry->load('patient'));
        }

        return $entry->load(['patient', 'visitType']);
    }

    /** The patient took themselves off. */
    public function leave(WaitingListEntry $entry): void
    {
        $this->takeOff($entry, WaitingListStatus::LEFT);
    }

    /** The secretary took them off. */
    public function remove(WaitingListEntry $entry): void
    {
        $this->takeOff($entry, WaitingListStatus::REMOVED);
    }

    private function takeOff(WaitingListEntry $entry, WaitingListStatus $status): void
    {
        if (! $entry->isWaiting()) {
            return;
        }

        $entry->update(['status' => $status, 'left_at' => now()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Booked, and closed
    |--------------------------------------------------------------------------
    */

    /**
     * A booking was just written: whoever it is for comes off the list.
     *
     * `$entryId` is the entry the secretary booked from, which may be for
     * another day than the one booked — the patient took whatever opened.
     * Without one, the patient's entry for the booked day is settled, so a
     * patient booked the ordinary way never also stays on that day's list.
     *
     * Runs inside BookingService's day lock and transaction, so a booking
     * that rolls back leaves the list as it was. Returns the entry the
     * secretary booked from, or null — the caller sends "booked from the
     * waiting list" only for that.
     */
    public function settleForBooking(Clinic $clinic, Booking $booking, ?int $entryId = null): ?WaitingListEntry
    {
        $chosen = null;

        if ($entryId !== null) {
            $chosen = $this->find($clinic, $entryId);

            if ($chosen->patient_id !== $booking->patient_id) {
                throw ApiException::make(
                    ApiErrorCode::WAITING_LIST_ENTRY_NOT_FOUND,
                    __('waiting_list.other_patient'),
                    http: 422,
                );
            }
        }

        $entries = $clinic->waitingListEntries()
            ->waiting()
            ->where('patient_id', $booking->patient_id)
            ->where(fn ($query) => $query
                ->whereDate('visit_date', $booking->visit_date->toDateString())
                ->when($chosen !== null, fn ($query) => $query->orWhere('id', $chosen->id)))
            ->get();

        foreach ($entries as $entry) {
            $entry->update([
                'status' => WaitingListStatus::BOOKED,
                'booking_id' => $booking->id,
                'left_at' => now(),
            ]);
        }

        return $chosen?->refresh();
    }

    /**
     * Closes every list whose day has ended, telling whoever is still on it.
     *
     * The message goes once per entry (`messaged_at`), and not at all when
     * the close comes more than `notify_within_hours` after the day ended —
     * a scheduler that was down should not send news about a day long gone.
     *
     * @return array{closed: int, messaged: int}
     */
    public function closeEndedDays(Clinic $clinic): array
    {
        $now = Carbon::now($clinic->timezone);
        $closed = 0;
        $messaged = 0;

        // An overnight period can end on the next calendar day, so yesterday
        // is still a candidate; anything older is over by definition.
        $dates = $clinic->waitingListEntries()
            ->waiting()
            ->whereDate('visit_date', '<=', $now->toDateString())
            ->distinct()
            ->pluck('visit_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique();

        foreach ($dates as $date) {
            $day = Carbon::parse($date, $clinic->timezone)->startOfDay();
            $end = $this->dayEndsAt($clinic, $day) ?? $day;

            if ($now->lt($end)) {
                continue;
            }

            $notify = $now->diffInMinutes($end, true)
                <= (int) config('clinic.waiting_list.notify_within_hours') * 60;

            $entries = $clinic->waitingListEntries()
                ->with('patient')
                ->waiting()
                ->onDate($date)
                ->get();

            foreach ($entries as $entry) {
                $entry->update(['status' => WaitingListStatus::CLOSED, 'left_at' => $now]);
                $closed++;

                if ($notify && $entry->messaged_at === null
                    && $this->messaging->sendWaitingListNoSlot($clinic, $entry) !== null) {
                    $entry->update(['messaged_at' => $now]);
                    $messaged++;
                }
            }
        }

        return ['closed' => $closed, 'messaged' => $messaged];
    }

    private function assertEnabled(Clinic $clinic): void
    {
        if (! $clinic->offersWaitingList()) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_DISABLED,
                __('waiting_list.disabled'),
                http: 403,
            );
        }
    }
}
