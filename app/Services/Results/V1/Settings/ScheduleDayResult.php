<?php

namespace App\Services\Results\V1\Settings;

use App\Models\ClinicSchedule;
use App\Models\ClinicSchedulePeriod;
use App\Services\Results\ServiceResult;
use App\Support\Wire;
use Illuminate\Support\Collection;

final class ScheduleDayResult extends ServiceResult
{
    public function __construct(private readonly ClinicSchedule $schedule) {}

    public function toArray(): array
    {
        return [
            'day_of_week' => Wire::enum($this->schedule->day_of_week, $this->schedule->day_of_week->label()),
            'is_open' => $this->schedule->is_open,
            'periods' => $this->schedule->periods
                ->map(fn (ClinicSchedulePeriod $period) => [
                    'id' => $period->id,
                    'start_time' => Wire::time($period->startTime()),
                    'end_time' => Wire::time($period->endTime()),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, ClinicSchedule>  $schedules
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $schedules): array
    {
        return $schedules
            ->map(fn (ClinicSchedule $schedule) => (new self($schedule))->toArray())
            ->values()
            ->all();
    }
}
