<?php

namespace Database\Factories;

use App\Enums\DayOfWeek;
use App\Models\Clinic;
use App\Models\ClinicSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicSchedule>
 */
class ClinicScheduleFactory extends Factory
{
    protected $model = ClinicSchedule::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'day_of_week' => DayOfWeek::SATURDAY,
            'is_open' => true,
        ];
    }

    public function on(DayOfWeek $day): static
    {
        return $this->state(fn () => ['day_of_week' => $day]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['is_open' => false]);
    }
}
