<?php

namespace App\Services\V1\Settings;

use App\DTOs\V1\Settings\ScheduleDayData;
use App\DTOs\V1\Settings\SchedulePeriodData;
use App\Enums\ApiErrorCode;
use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\ClinicSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleService
{
    /**
     * All seven days in business-week order, Saturday first.
     *
     * @return Collection<int, ClinicSchedule>
     */
    public function week(Clinic $clinic): Collection
    {
        return $clinic->schedules()->with('periods')->orderBy('day_of_week')->get();
    }

    public function updateDay(Clinic $clinic, ScheduleDayData $data): ClinicSchedule
    {
        $schedule = $this->findDay($clinic, $data->day);

        $this->guardPeriods($data);
        $this->guardOvernightNeighbours($clinic, $data);

        return DB::transaction(function () use ($schedule, $data) {
            $schedule->update(['is_open' => $data->isOpen]);

            // Replace wholesale — period ids are not stable across an edit.
            $schedule->periods()->delete();

            foreach ($data->periods as $period) {
                $schedule->periods()->create($period->toAttributes());
            }

            return $schedule->load('periods');
        });
    }

    public function findDay(Clinic $clinic, DayOfWeek $day): ClinicSchedule
    {
        $schedule = $clinic->schedules()->where('day_of_week', $day->value)->first();

        if ($schedule === null) {
            throw ApiException::make(
                ApiErrorCode::SCHEDULE_DAY_NOT_FOUND,
                __('settings.schedule.day_not_found'),
                http: 404,
            );
        }

        return $schedule;
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
     * The hours after midnight belong to the evening that ran into them, so
     * nothing else may be open then — not this day, where 01:00 would name
     * two different moments, and not the next one, where two days' bookings
     * would share the same minutes without either seeing the other.
     */
    private function guardOvernightNeighbours(Clinic $clinic, ScheduleDayData $data): void
    {
        $previous = DayOfWeek::from(($data->day->value + 6) % 7);
        $next = DayOfWeek::from(($data->day->value + 1) % 7);

        $ownOvernight = collect($data->periods)->first(fn (SchedulePeriodData $period) => $period->crossesMidnight());

        // This day's evening against its own morning, and against tomorrow's.
        if ($ownOvernight !== null) {
            $this->guardNothingStartsBefore($data->day, $ownOvernight->endTime, $data->periods);
            $this->guardNothingStartsBefore($data->day, $ownOvernight->endTime, $this->storedPeriods($clinic, $next));
        }

        // Yesterday's evening against this day.
        $yesterdays = collect($this->storedPeriods($clinic, $previous))
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
     * An open day's saved periods; a closed day has none that count.
     *
     * @return list<SchedulePeriodData>
     */
    private function storedPeriods(Clinic $clinic, DayOfWeek $day): array
    {
        $schedule = $clinic->scheduleFor($day);

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
