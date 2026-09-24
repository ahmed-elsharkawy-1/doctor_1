<?php

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Models\Clinic;
use App\Models\SlotHold;
use App\Models\VisitType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<SlotHold>
 */
class SlotHoldFactory extends Factory
{
    protected $model = SlotHold::class;

    public function definition(): array
    {
        $startAt = Carbon::today()->setTime(9, 0);

        return [
            'clinic_id' => Clinic::factory(),
            'visit_type_id' => VisitType::factory(),
            'visit_date' => $startAt->toDateString(),
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes(20),
            'token' => Str::random(48),
            'source' => BookingSource::CLINIC,
            'expires_at' => Carbon::now()->addMinutes(
                (int) config('clinic.self_booking.hold_ttl_minutes'),
            ),
        ];
    }

    /**
     * Anchor the hold to a clinic, reusing its visit type so the generated
     * graph stays internally consistent — the same contract BookingFactory's
     * forClinic() keeps.
     */
    public function forClinic(Clinic $clinic): static
    {
        return $this->state(function () use ($clinic) {
            $visitType = $clinic->visitTypes()->first()
                ?? VisitType::factory()->create(['clinic_id' => $clinic->id]);

            return [
                'clinic_id' => $clinic->id,
                'visit_type_id' => $visitType->id,
            ];
        });
    }

    public function at(Carbon $startAt, ?int $durationMinutes = null): static
    {
        return $this->state(fn () => [
            'visit_date' => $startAt->toDateString(),
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes($durationMinutes ?? 20),
        ]);
    }

    public function heldBy(string $token): static
    {
        return $this->state(fn () => ['token' => $token]);
    }

    public function byPatient(): static
    {
        return $this->state(fn () => ['source' => BookingSource::PATIENT_WEB]);
    }

    /** Already lapsed — it blocks nothing. */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => Carbon::now()->subMinute()]);
    }
}
