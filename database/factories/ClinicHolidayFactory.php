<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicHoliday>
 */
class ClinicHolidayFactory extends Factory
{
    protected $model = ClinicHoliday::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'date' => now()->addDays(3)->toDateString(),
            'note' => 'إجازة',
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['date' => $date]);
    }
}
