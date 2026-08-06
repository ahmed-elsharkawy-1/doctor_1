<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\VisitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitType>
 */
class VisitTypeFactory extends Factory
{
    protected $model = VisitType::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'name' => 'كشف',
            'duration_minutes' => 20,
            'price' => 300.00,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function followUp(): static
    {
        return $this->state(fn () => [
            'name' => 'إعادة',
            'duration_minutes' => 10,
            'price' => 150.00,
        ]);
    }

    public function procedure(): static
    {
        return $this->state(fn () => [
            'name' => 'إجراء',
            'duration_minutes' => 30,
            'price' => 800.00,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
