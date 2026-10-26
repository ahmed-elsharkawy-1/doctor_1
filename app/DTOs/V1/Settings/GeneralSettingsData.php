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
        public readonly ?string $reminderTime = null,
        public readonly ?int $reminderPosition = null,
        public readonly ?int $whatsAppCancelCutoffMinutes = null,
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
            reminderTime: isset($validated['reminder_time'])
                ? substr(trim((string) $validated['reminder_time']), 0, 5)
                : null,
            reminderPosition: isset($validated['reminder_position'])
                ? (int) $validated['reminder_position']
                : null,
            whatsAppCancelCutoffMinutes: isset($validated['whatsapp_cancel_cutoff_minutes'])
                ? (int) $validated['whatsapp_cancel_cutoff_minutes']
                : null,
        );
    }

    /**
     * Only the keys actually submitted, so a partial update leaves the rest
     * untouched.
     *
     * @return array<string, int|string>
     */
    public function toAttributes(): array
    {
        return array_filter(
            [
                'booking_window_days' => $this->bookingWindowDays,
                'first_visit_only_days' => $this->firstVisitOnlyDays,
                'patient_arrival_lead_minutes' => $this->patientArrivalLeadMinutes,
                'reminder_time' => $this->reminderTime,
                'reminder_position' => $this->reminderPosition,
                'whatsapp_cancel_cutoff_minutes' => $this->whatsAppCancelCutoffMinutes,
            ],
            static fn (int|string|null $value): bool => $value !== null && $value !== '',
        );
    }
}
