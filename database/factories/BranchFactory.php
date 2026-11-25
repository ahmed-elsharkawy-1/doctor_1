<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Clinic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'name' => 'عيادة '.$this->faker->unique()->city(),
            'address' => $this->faker->address(),
            'latitude' => $this->faker->latitude(22, 31),
            'longitude' => $this->faker->longitude(25, 35),
            'phone' => null,
            'is_active' => true,
            'sort_order' => 1,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
