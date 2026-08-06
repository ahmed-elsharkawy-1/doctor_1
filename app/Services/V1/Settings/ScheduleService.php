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
     * starts, and no two may overlap — otherwise slot generation would produce
     * duplicate or impossible times.
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
            if (! $period->startsBeforeItEnds()) {
                throw ApiException::make(
                    ApiErrorCode::SCHEDULE_PERIOD_INVALID,
                    __('settings.schedule.period_invalid'),
                    details: ['start_time' => $period->startTime, 'end_time' => $period->endTime],
                );
            }
        }

        $this->guardAgainstOverlaps($data->periods);
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
