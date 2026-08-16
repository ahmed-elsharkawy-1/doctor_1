<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'name' => $this->faker->firstName('female').' '.$this->faker->lastName(),
            'phone' => '+20'.$this->faker->unique()->numerify('1#########'),
            'age' => $this->faker->numberBetween(18, 80),
            'whatsapp_opt_in_at' => now(),
        ];
    }
}
