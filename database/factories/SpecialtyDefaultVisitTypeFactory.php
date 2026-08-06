<?php

namespace Database\Factories;

use App\Models\Specialty;
use App\Models\SpecialtyDefaultVisitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpecialtyDefaultVisitType>
 */
class SpecialtyDefaultVisitTypeFactory extends Factory
{
    protected $model = SpecialtyDefaultVisitType::class;

    public function definition(): array
    {
        return [
            'specialty_id' => Specialty::factory(),
            'name_ar' => 'كشف '.$this->faker->unique()->numberBetween(1, 9999),
            'name_en' => 'Checkup',
            'duration_minutes' => 20,
            'sort_order' => 0,
        ];
    }
}
