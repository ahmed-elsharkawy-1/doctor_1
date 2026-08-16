<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::CLINIC,
            'phone' => '+20'.$this->faker->unique()->numerify('1#########'),
            'locale' => 'ar',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => ['role' => UserRole::SUPER_ADMIN]);
    }

    public function owner(?Doctor $doctor = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::CLINIC,
            'doctor_id' => $doctor?->id,
        ]);
    }

    public function secretary(): static
    {
        return $this->state(fn () => ['role' => UserRole::CLINIC]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Attach the user to a clinic once created.
     */
    public function inClinic(Clinic $clinic): static
    {
        return $this->afterCreating(fn (User $user) => $user->clinics()->syncWithoutDetaching([$clinic->id]));
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
