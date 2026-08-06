<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\VisitType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $clinic = Clinic::factory();
        $startAt = Carbon::today()->setTime(9, 0);
        $duration = 20;

        return [
            'clinic_id' => $clinic,
            'doctor_id' => Doctor::factory(),
            'patient_id' => Patient::factory(),
            'visit_type_id' => VisitType::factory(),
            'visit_date' => $startAt->toDateString(),
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes($duration),
            'duration_minutes' => $duration,
            'price' => 300.00,
            'status' => BookingStatus::BOOKED,
            'is_overbooked' => false,
        ];
    }

    /**
     * Anchor the booking to a clinic, reusing its doctor and visit type so the
     * generated graph stays internally consistent.
     */
    public function forClinic(Clinic $clinic): static
    {
        return $this->state(function () use ($clinic) {
            $doctor = $clinic->doctors()->first() ?? Doctor::factory()->create(['clinic_id' => $clinic->id]);
            $visitType = $clinic->visitTypes()->first() ?? VisitType::factory()->create(['clinic_id' => $clinic->id]);

            return [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'visit_type_id' => $visitType->id,
                'duration_minutes' => $visitType->duration_minutes,
                'price' => $visitType->price,
                'patient_id' => Patient::factory()->create(['clinic_id' => $clinic->id])->id,
            ];
        });
    }

    public function at(Carbon $startAt, ?int $durationMinutes = null): static
    {
        return $this->state(function (array $attributes) use ($startAt, $durationMinutes) {
            $duration = $durationMinutes ?? $attributes['duration_minutes'] ?? 20;

            return [
                'visit_date' => $startAt->toDateString(),
                'start_at' => $startAt,
                'end_at' => $startAt->copy()->addMinutes($duration),
                'duration_minutes' => $duration,
            ];
        });
    }

    public function arrived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::ARRIVED,
            'arrived_at' => Carbon::parse($attributes['start_at']),
        ]);
    }

    public function withDoctor(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::WITH_DOCTOR,
            'arrived_at' => Carbon::parse($attributes['start_at']),
            'called_in_at' => Carbon::parse($attributes['start_at']),
        ]);
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::DONE,
            'arrived_at' => Carbon::parse($attributes['start_at']),
            'called_in_at' => Carbon::parse($attributes['start_at']),
            'completed_at' => Carbon::parse($attributes['end_at']),
        ]);
    }

    public function cancelled(CancelReason $reason = CancelReason::PATIENT_CANCELLED): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::CANCELLED,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ]);
    }
}
