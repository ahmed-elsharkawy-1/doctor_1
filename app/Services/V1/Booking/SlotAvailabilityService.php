<?php

namespace App\Services\V1\Booking;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\ClinicSchedulePeriod;
use App\Models\VisitType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a clinic's hours plus what is already booked into the list of start
 * times the secretary sees — SPEC §5.1.
 *
 * Returns the *full* annotated list, available and not, because the booking
 * screen greys out taken times rather than hiding them.
 *
 * Timezone convention: booking times are stored as the clinic's local wall
 * clock, and every comparison here re-attaches the clinic's timezone
 * explicitly. Nothing depends on config('app.timezone') — a clinic in another
 * timezone must not change how any of this behaves.
 */
class SlotAvailabilityService
{
    public function for(Clinic $clinic, Carbon $date, VisitType $visitType, ?int $ignoreBookingId = null): DayAvailability
    {
        $date = $this->clinicDate($clinic, $date);

        if (! $this->isWithinWindow($clinic, $date)) {
            return DayAvailability::closed($date, $visitType, ClosedReason::OUTSIDE_WINDOW);
        }

        if ($this->isHoliday($clinic, $date)) {
            return DayAvailability::closed($date, $visitType, ClosedReason::HOLIDAY);
        }

        $schedule = $clinic->scheduleFor(DayOfWeek::fromDate($date));

        if ($schedule === null || ! $schedule->is_open) {
            return DayAvailability::closed($date, $visitType, ClosedReason::WEEKLY_CLOSED);
        }

        $taken = $this->takenIntervals($clinic, $date, $ignoreBookingId);

        $slots = [];

        foreach ($schedule->periods as $period) {
            foreach ($this->candidates($clinic, $date, $period, $visitType) as $candidate) {
                $slots[] = new Slot(
                    startAt: $candidate['start'],
                    endAt: $candidate['end'],
                    isAvailable: ! $this->overlapsAnything($candidate['start'], $candidate['end'], $taken),
                );
            }
        }

        usort($slots, static fn (Slot $a, Slot $b): int => $a->startAt <=> $b->startAt);

        return DayAvailability::open($date, $visitType, $slots);
    }

    /**
     * Is this exact start time bookable? Used by the booking write path, which
     * must not trust a slot the client computed earlier.
     */
    public function isBookable(Clinic $clinic, Carbon $startAt, VisitType $visitType, ?int $ignoreBookingId = null): bool
    {
        $availability = $this->for($clinic, $startAt->copy()->startOfDay(), $visitType, $ignoreBookingId);

        foreach ($availability->slots as $slot) {
            if ($slot->startAt->equalTo($startAt)) {
                return $slot->isAvailable;
            }
        }

        return false;
    }

    public function closedReasonFor(Clinic $clinic, Carbon $date, VisitType $visitType): ?ClosedReason
    {
        return $this->for($clinic, $date, $visitType)->closedReason;
    }

    /**
     * A rolling window from today — `booking_window_days` inclusive of today,
     * so 7 means today plus the next six days (SPEC decision #12).
     *
     * Compared as calendar dates, not instants: "is this day in the window" is
     * a question about the clinic's calendar, and an instant comparison would
     * answer it differently depending on the caller's timezone.
     */
    public function isWithinWindow(Clinic $clinic, Carbon $date): bool
    {
        $today = $this->today($clinic);
        $lastDay = $today->copy()->addDays($clinic->booking_window_days - 1);

        $day = $date->toDateString();

        return $day >= $today->toDateString() && $day <= $lastDay->toDateString();
    }

    /**
     * The same calendar day, anchored at midnight in the clinic's timezone.
     */
    private function clinicDate(Clinic $clinic, Carbon $date): Carbon
    {
        return Carbon::parse($date->toDateString(), $clinic->timezone)->startOfDay();
    }

    public function today(Clinic $clinic): Carbon
    {
        return Carbon::now($clinic->timezone)->startOfDay();
    }

    public function isHoliday(Clinic $clinic, Carbon $date): bool
    {
        return $clinic->holidays()->whereDate('date', $date->toDateString())->exists();
    }

    /**
     * Every start time that fits wholly inside the period.
     *
     * A 30-minute procedure is not offered at 13:50 when the period ends at
     * 14:00 — the visit has to finish before the clinic closes.
     *
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private function candidates(Clinic $clinic, Carbon $date, ClinicSchedulePeriod $period, VisitType $visitType): array
    {
        $step = max(1, $clinic->slot_step_minutes);
        $duration = $visitType->duration_minutes;

        $periodStart = $this->at($clinic, $date, $period->startTime());
        $periodEnd = $this->at($clinic, $date, $period->endTime());

        $candidates = [];
        $cursor = $periodStart->copy();

        while (true) {
            $end = $cursor->copy()->addMinutes($duration);

            if ($end->greaterThan($periodEnd)) {
                break;
            }

            $candidates[] = ['start' => $cursor->copy(), 'end' => $end];
            $cursor->addMinutes($step);
        }

        return $candidates;
    }

    /**
     * Bookings that hold time on this date. Cancelled ones free their slot.
     *
     * @return Collection<int, array{start: Carbon, end: Carbon}>
     */
    private function takenIntervals(Clinic $clinic, Carbon $date, ?int $ignoreBookingId): Collection
    {
        return $clinic->bookings()
            ->onDate($date->toDateString())
            ->occupyingSlot()
            ->when($ignoreBookingId !== null, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->get(['id', 'start_at', 'end_at'])
            ->map(fn (Booking $booking) => [
                'start' => $this->clinicTime($clinic, $booking->start_at),
                'end' => $this->clinicTime($clinic, $booking->end_at),
            ]);
    }

    /**
     * Stored times are the clinic's local wall clock. Eloquent hands them back
     * labelled with whatever config('app.timezone') happens to be, so the
     * clinic's timezone is re-attached here rather than converted — the wall
     * clock is the truth.
     */
    private function clinicTime(Clinic $clinic, Carbon $stored): Carbon
    {
        return Carbon::parse($stored->format('Y-m-d H:i:s'), $clinic->timezone);
    }

    /**
     * Half-open intervals: [start, end). A visit ending at 09:20 does not
     * collide with one starting at 09:20.
     *
     * @param  Collection<int, array{start: Carbon, end: Carbon}>  $taken
     */
    private function overlapsAnything(Carbon $start, Carbon $end, Collection $taken): bool
    {
        foreach ($taken as $interval) {
            if ($start->lessThan($interval['end']) && $end->greaterThan($interval['start'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Built from the calendar date and a wall-clock time, never by shifting an
     * existing instant — a timezone conversion could roll the date over.
     */
    private function at(Clinic $clinic, Carbon $date, string $time): Carbon
    {
        return Carbon::parse($date->toDateString().' '.$time, $clinic->timezone);
    }
}
