<?php

namespace App\DTOs\V1\Booking;

/**
 * A booking as submitted from the New Booking screen.
 */
final class BookingData
{
    public function __construct(
        public readonly ?int $patientId,
        public readonly ?string $patientName,
        public readonly ?string $phone,
        public readonly ?int $age,
        public readonly bool $whatsappOptIn,
        public readonly int $visitTypeId,
        public readonly string $date,
        public readonly string $startTime,
        public readonly ?string $notes = null,
        public readonly bool $force = false,
        public readonly bool $updatePatientName = false,
        /** The postponed booking this one replaces, when booked from the call list. */
        public readonly ?int $rebookingForBookingId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            patientId: isset($validated['patient_id']) ? (int) $validated['patient_id'] : null,
            patientName: isset($validated['patient_name']) ? trim((string) $validated['patient_name']) : null,
            phone: isset($validated['phone']) ? (string) $validated['phone'] : null,
            age: isset($validated['age']) ? (int) $validated['age'] : null,
            whatsappOptIn: (bool) ($validated['whatsapp_opt_in'] ?? true),
            visitTypeId: (int) $validated['visit_type_id'],
            date: (string) $validated['date'],
            startTime: substr((string) $validated['start_time'], 0, 5),
            notes: isset($validated['notes']) ? trim((string) $validated['notes']) : null,
            // Books past a full day, or outside working hours, on the
            // secretary's explicit confirmation (SPEC decision #16).
            force: (bool) ($validated['force'] ?? false),
            updatePatientName: (bool) ($validated['update_patient_name'] ?? false),
            rebookingForBookingId: isset($validated['rebooking_for_booking_id'])
                ? (int) $validated['rebooking_for_booking_id']
                : null,
        );
    }
}
