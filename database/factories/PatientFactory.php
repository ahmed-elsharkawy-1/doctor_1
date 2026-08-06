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
            'code' => strtoupper($this->faker->unique()->lexify('????')).$this->faker->numerify('####'),
            'name' => $this->faker->firstName('female').' '.$this->faker->lastName(),
            'phone' => '+20'.$this->faker->unique()->numerify('1#########'),
        ];
    }
}
