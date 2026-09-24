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
            'patient_arrival_lead_minutes' => $defaults['patient_arrival_lead_minutes'],
            // Off, exactly as a real clinic starts. Left null the model would
            // report null rather than false until it was read back from the
            // database, which is a difference nobody should have to know about.
            'self_booking_enabled' => false,
            // Null on purpose: the common case is a clinic nobody has tuned,
            // which falls back to the platform default.
            'patient_booking_window_days' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** A clinic whose public booking page is open. */
    public function selfBooking(): static
    {
        return $this->state(fn () => ['self_booking_enabled' => true]);
    }
}
