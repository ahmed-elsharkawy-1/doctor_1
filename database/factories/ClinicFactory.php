<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
{
    protected $model = Clinic::class;

    public function definition(): array
    {
        $defaults = config('clinic.defaults');

        return [
            'specialty_id' => Specialty::factory(),
            'name' => 'عيادة '.$this->faker->unique()->lastName(),
            'address' => $this->faker->address(),
            'phone' => '+20'.$this->faker->numerify('1#########'),
            'timezone' => $defaults['timezone'],
            'country_code' => config('clinic.phone.default_country'),
            'booking_window_days' => $defaults['booking_window_days'],
            'first_visit_only_days' => $defaults['first_visit_only_days'],
            'slot_step_minutes' => $defaults['slot_step_minutes'],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
