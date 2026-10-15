<?php

namespace Database\Factories;

use App\Enums\DoctorSex;
use App\Models\Clinic;
use App\Models\Doctor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'sex' => fake()->randomElement(DoctorSex::cases()),
            // Trimmed: Faker's ar_EG list carries one name with a trailing
            // space ("محمد "), which made a name-sensitive test fail once in
            // every few hundred runs.
            'name' => 'د. '.trim($this->faker->firstName()),
            'phone' => '+20'.$this->faker->numerify('1#########'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
