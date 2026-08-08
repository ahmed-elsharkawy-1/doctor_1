<?php

namespace App\DTOs\V1\Settings;

/**
 * Clinic-level numbers exposed on the settings screen (SPEC §4.6).
 *
 * slot_step_minutes and timezone stay in the admin panel — they are set up
 * once and are not day-to-day settings.
 */
final class GeneralSettingsData
{
    public function __construct(
        public readonly ?int $bookingWindowDays = null,
        public readonly ?int $firstVisitOnlyDays = null,
        public readonly ?int $patientArrivalLeadMinutes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            bookingWindowDays: isset($validated['booking_window_days'])
                ? (int) $validated['booking_window_days']
                : null,
            firstVisitOnlyDays: isset($validated['first_visit_only_days'])
                ? (int) $validated['first_visit_only_days']
                : null,
            patientArrivalLeadMinutes: isset($validated['patient_arrival_lead_minutes'])
                ? (int) $validated['patient_arrival_lead_minutes']
                : null,
        );
    }

    /**
     * Only the keys actually submitted, so a partial update leaves the rest
     * untouched.
     *
     * @return array<string, int>
     */
    public function toAttributes(): array
    {
        return array_filter(
            [
                'booking_window_days' => $this->bookingWindowDays,
                'first_visit_only_days' => $this->firstVisitOnlyDays,
                'patient_arrival_lead_minutes' => $this->patientArrivalLeadMinutes,
            ],
            static fn (?int $value): bool => $value !== null,
        );
    }
}
