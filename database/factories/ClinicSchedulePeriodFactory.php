<?php

namespace Database\Factories;

use App\Models\ClinicSchedule;
use App\Models\ClinicSchedulePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicSchedulePeriod>
 */
class ClinicSchedulePeriodFactory extends Factory
{
    protected $model = ClinicSchedulePeriod::class;

    public function definition(): array
    {
        return [
            'clinic_schedule_id' => ClinicSchedule::factory(),
            'start_time' => '09:00',
            'end_time' => '14:00',
        ];
    }

    public function between(string $start, string $end): static
    {
        return $this->state(fn () => [
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }
}
