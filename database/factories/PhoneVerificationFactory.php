<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\PhoneVerification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PhoneVerification>
 */
class PhoneVerificationFactory extends Factory
{
    protected $model = PhoneVerification::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'phone' => '+20'.$this->faker->numerify('1#########'),
            'code_hash' => Hash::make('123456'),
            'expires_at' => Carbon::now()->addMinutes(
                (int) config('clinic.self_booking.otp.ttl_minutes'),
            ),
            'attempts' => 0,
        ];
    }

    public function forPhone(Clinic $clinic, string $phone): static
    {
        return $this->state(fn () => [
            'clinic_id' => $clinic->id,
            'phone' => $phone,
        ]);
    }

    public function withCode(string $code): static
    {
        return $this->state(fn () => ['code_hash' => Hash::make($code)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => Carbon::now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(fn () => ['verified_at' => Carbon::now()]);
    }

    /** Every guess spent — the code is burned. */
    public function exhausted(): static
    {
        return $this->state(fn () => [
            'attempts' => (int) config('clinic.self_booking.otp.max_attempts'),
        ]);
    }
}
