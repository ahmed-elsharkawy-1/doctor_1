<?php

namespace App\Services\V1\Settings;

use App\DTOs\V1\Settings\ScheduleDayData;
use App\DTOs\V1\Settings\SchedulePeriodData;
use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\ClinicSchedule;
use App\Support\Wire;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A branch's weekly working hours (ELA-20).
 *
 * Each branch has its own week; a day with no period is that branch's day
 * off. The doctor can be at only one branch at a time, so a period may not
 * overlap one at another branch on the same day, and an evening running past
 * midnight blocks the small hours at every branch. A gap under
 * `clinic.branches.min_gap_minutes` between two branches is allowed, with a
 * warning: the doctor has to get from one to the other.
 */
class ScheduleService
{
    /**
     * All seven days in business-week order, Saturday first.
     *
     * @return Collection<int, ClinicSchedule>
     */
    public function week(Branch $branch): Collection
    {
        return $branch->schedules()->with('periods')->get();
    }

    /**
     * The same week for every branch of the clinic, for the overview that
     * shows the doctor's whole week at once.
     *
     * @param  Collection<int, Branch>  $branches
     * @return Collection<int, Collection<int, ClinicSchedule>> keyed by branch id
     */
    public function weeks(Collection $branches): Collection
    {
        return $branches->mapWithKeys(fn (Branch $branch) => [$branch->id => $this->week($branch)]);
    }

    /**
     * Applies to new bookings only (ELA-20). Bookings already made outside
     * the new hours are counted, not moved: the secretary handles them.
     */
    public function updateDay(Branch $branch, ScheduleDayData $data): ScheduleDayUpdate
    {
        $schedule = $this->findDay($branch, $data->day);

        $this->guardPeriods($data);
        $this->guardOtherBranches($branch, $data);
        $this->guardOvernightNeighbours($branch, $data);

        $saved = DB::transaction(function () use ($schedule, $data) {
            $schedule->update(['is_open' => $data->isOpen]);

            // Replace wholesale — period ids are not stable across an edit.
            $schedule->periods()->delete();

            foreach ($data->periods as $period) {
                $schedule->periods()->create($period->toAttributes());
            }

            return $schedule->load('periods');
        });

        return new ScheduleDayUpdate(
            schedule: $saved,
            shortGaps: $this->shortGaps($branch, $data),
            bookingsOutsideHours: $this->bookingsOutsideHours($branch, $data),
        );
    }

    public function findDay(Branch $branch, DayOfWeek $day): ClinicSchedule
    {
        $schedule = $branch->schedules()->where('day_of_week', $day->value)->first()
            // A branch made before its week was: give it the closed day now.
            ?? $branch->schedules()->create([
                'clinic_id' => $branch->clinic_id,
                'day_of_week' => $day,
                'is_open' => false,
            ]);

        return $schedule;
    }

    /**
     * Seven closed days, so a new branch has a week to fill in and takes no
     * booking until it is (ELA-16). Idempotent.
     */
    public function seedClosedWeek(Branch $branch): void
    {
        $existing = $branch->schedules()->pluck('day_of_week')
            ->map(fn ($day) => $day instanceof DayOfWeek ? $day->value : (int) $day)
            ->all();

        foreach (DayOfWeek::cases() as $day) {
            if (! in_array($day->value, $existing, true)) {
                $branch->schedules()->create(['clinic_id' => $branch->clinic_id, 'day_of_week' => $day, 'is_open' => false]);
            }
        }
    }

    /**
     * An open day needs at least one period, every period must end after it
     * starts (or run past midnight, within limits), and no two may overlap —
     * otherwise slot generation would produce duplicate or impossible times.
     */
    private function guardPeriods(ScheduleDayData $data): void
    {
        if (! $data->isOpen) {
            return;
        }

        if ($data->periods === []) {
            throw ApiException::make(
                ApiErrorCode::SCHEDULE_PERIOD_INVALID,
                __('settings.schedule.periods_required'),
            );
        }

        foreach ($data->periods as $period) {
            if (! $period->isValid()) {
                throw ApiException::make(
                    ApiErrorCode::SCHEDULE_PERIOD_INVALID,
                    __('settings.schedule.period_invalid', [
                        'time' => config('clinic.schedule.latest_overnight_close'),
                    ]),
                    details: ['start_time' => $period->startTime, 'end_time' => $period->endTime],
                );
            }
        }

        $this->guardAgainstOverlaps($data->periods);
    }

    /**
     * The doctor can't be at two branches at once (ELA-20): refused, naming
     * the branch and the period in the way.
     */
    private function guardOtherBranches(Branch $branch, ScheduleDayData $data): void
    {
        foreach ($this->otherBranchesPeriods($branch, $data->day) as [$other, $periods]) {
            foreach ($data->periods as $mine) {
                foreach ($periods as $theirs) {
                    if ($mine->overlaps($theirs)) {
                        throw ApiException::make(
                            ApiErrorCode::SCHEDULE_BRANCH_CONFLICT,
                            __('settings.schedule.branch_conflict', [
                                'branch' => $other->name,
                                'from' => Wire::time($theirs->startTime)['display'],
                                'to' => Wire::time($theirs->endTime)['display'],
                            ]),
                            details: [
                                'branch_id' => $other->id,
                                'branch' => $other->name,
                                'period' => $theirs->toAttributes(),
                            ],
                            http: 409,
                        );
                    }
                }
            }
        }
    }

    /**
     * The hours after midnight belong to the evening that ran into them, so
     * nothing else may be open then — not this day, where 01:00 would name
     * two different moments, and not the next one, where two days' bookings
     * would share the same minutes without either seeing the other. At any
     * branch: it is the same doctor.
     */
    private function guardOvernightNeighbours(Branch $branch, ScheduleDayData $data): void
    {
        $previous = DayOfWeek::from(($data->day->value + 6) % 7);
        $next = DayOfWeek::from(($data->day->value + 1) % 7);

        $ownOvernight = collect($data->periods)->first(fn (SchedulePeriodData $period) => $period->crossesMidnight());

        // This day's evening against its own morning, and against tomorrow's.
        if ($ownOvernight !== null) {
            $this->guardNothingStartsBefore($data->day, $ownOvernight->endTime, $data->periods);
            $this->guardNothingStartsBefore($data->day, $ownOvernight->endTime, $this->doctorsPeriods($branch, $next));
        }

        // Yesterday's evening, at any branch, against this day.
        $yesterdays = collect($this->doctorsPeriods($branch, $previous))
            ->first(fn (SchedulePeriodData $period) => $period->crossesMidnight());

        if ($yesterdays !== null) {
            $this->guardNothingStartsBefore($previous, $yesterdays->endTime, $data->periods);
        }
    }

    /**
     * @param  list<SchedulePeriodData>  $periods
     */
    private function guardNothingStartsBefore(DayOfWeek $owner, string $close, array $periods): void
    {
        foreach ($periods as $period) {
            if ($period->startTime < $close) {
                throw ApiException::make(
                    ApiErrorCode::SCHEDULE_PERIOD_OVERLAP,
                    __('settings.schedule.overnight_clash', ['day' => $owner->label(), 'time' => $close]),
                    details: ['day' => $owner->value, 'closes_at' => $close, 'period' => $period->toAttributes()],
                );
            }
        }
    }

    /**
     * Two branches the same day with less than the minimum between them.
     * Allowed: only a warning (ELA-20).
     *
     * One entry per period of the other branch that is too close, naming it,
     * with the smallest gap to any of this day's periods.
     *
     * @return list<array{branch_id: int, branch: string, from: string, to: string, minutes: int}>
     */
    private function shortGaps(Branch $branch, ScheduleDayData $data): array
    {
        $minimum = (int) config('clinic.branches.min_gap_minutes');
        $gaps = [];

        foreach ($this->otherBranchesPeriods($branch, $data->day) as [$other, $periods]) {
            foreach ($periods as $theirs) {
                $closest = null;

                foreach ($data->periods as $mine) {
                    $gap = max($theirs->startMinute() - $mine->endMinute(), $mine->startMinute() - $theirs->endMinute());

                    if ($gap >= 0 && $gap < $minimum && ($closest === null || $gap < $closest)) {
                        $closest = $gap;
                    }
                }

                if ($closest !== null) {
                    $gaps[] = [
                        'branch_id' => $other->id,
                        'branch' => $other->name,
                        'from' => $theirs->startTime,
                        'to' => $theirs->endTime,
                        'minutes' => $closest,
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * Upcoming bookings on this weekday whose time no longer falls in this
     * branch's hours.
     *
     * Bookings don't carry their branch until ELA-18, so one that sits in
     * another branch's hours that day is taken to be that branch's and left
     * out; otherwise saving one branch would report the other's patients.
     */
    private function bookingsOutsideHours(Branch $branch, ScheduleDayData $data): int
    {
        $today = Carbon::now($branch->clinic->timezone)->startOfDay();
        $otherBranches = collect($this->otherBranchesPeriods($branch, $data->day))
            ->flatMap(fn (array $pair) => $pair[1]);
        $periods = collect($data->periods)->concat($otherBranches);

        return Booking::query()
            ->where('clinic_id', $branch->clinic_id)
            ->whereIn('status', BookingStatus::pending())
            ->whereDate('visit_date', '>=', $today->toDateString())
            ->whereNotNull('start_at')
            ->get(['visit_date', 'start_at'])
            ->filter(fn (Booking $booking) => DayOfWeek::fromDate($booking->visit_date) === $data->day)
            ->reject(function (Booking $booking) use ($periods): bool {
                $minute = $this->minuteOfVisitDay($booking);

                return $periods->contains(
                    fn (SchedulePeriodData $period) => $minute >= $period->startMinute() && $minute < $period->endMinute(),
                );
            })
            ->count();
    }

    /**
     * Minutes from the start of the visit day; a booking after midnight on an
     * evening that runs past it counts on from 24:00, as its period does.
     */
    private function minuteOfVisitDay(Booking $booking): int
    {
        $start = Carbon::parse($booking->start_at);
        $minutes = $start->hour * 60 + $start->minute;

        return $start->toDateString() > Carbon::parse($booking->visit_date)->toDateString() ? $minutes + 24 * 60 : $minutes;
    }

    /**
     * @return list<array{0: Branch, 1: list<SchedulePeriodData>}>
     */
    private function otherBranchesPeriods(Branch $branch, DayOfWeek $day): array
    {
        return Branch::query()
            ->where('clinic_id', $branch->clinic_id)
            ->whereKeyNot($branch->id)
            ->get()
            ->map(fn (Branch $other) => [$other, $this->storedPeriods($other, $day)])
            ->filter(fn (array $pair) => $pair[1] !== [])
            ->values()
            ->all();
    }

    /**
     * Every saved period the doctor has that day, at any branch.
     *
     * @return list<SchedulePeriodData>
     */
    private function doctorsPeriods(Branch $branch, DayOfWeek $day): array
    {
        return Branch::query()
            ->where('clinic_id', $branch->clinic_id)
            ->get()
            ->flatMap(fn (Branch $any) => $this->storedPeriods($any, $day))
            ->values()
            ->all();
    }

    /**
     * An open day's saved periods; a closed day has none that count.
     *
     * @return list<SchedulePeriodData>
     */
    private function storedPeriods(Branch $branch, DayOfWeek $day): array
    {
        $schedule = $branch->scheduleFor($day);

        if ($schedule === null || ! $schedule->is_open) {
            return [];
        }

        return $schedule->periods
            ->map(fn ($period) => SchedulePeriodData::fromArray($period->only(['start_time', 'end_time'])))
            ->values()
            ->all();
    }

    /**
     * @param  list<SchedulePeriodData>  $periods
     */
    private function guardAgainstOverlaps(array $periods): void
    {
        $count = count($periods);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($periods[$i]->overlaps($periods[$j])) {
                    throw ApiException::make(
                        ApiErrorCode::SCHEDULE_PERIOD_OVERLAP,
                        __('settings.schedule.period_overlap'),
                        details: [
                            'first' => $periods[$i]->toAttributes(),
                            'second' => $periods[$j]->toAttributes(),
                        ],
                    );
                }
            }
        }
    }
}
