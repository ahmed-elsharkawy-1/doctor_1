<?php

namespace App\DTOs\V1\Booking;

use App\Enums\BookingKind;
use App\Enums\BookingSource;
use App\Enums\PatientLocation;

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
        public readonly ?string $startTime,
        public readonly BookingKind $bookingKind = BookingKind::NORMAL,
        public readonly ?PatientLocation $patientLocation = null,
        public readonly ?string $notes = null,
        public readonly bool $updatePatientName = false,
        /** The postponed booking this one replaces, when booked from the call list. */
        public readonly ?int $rebookingForBookingId = null,
        /**
         * Which door this booking came through.
         *
         * Set by the caller, never by the client — see fromArray(). Defaults
         * to CLINIC so every existing call site keeps its meaning untouched.
         */
        public readonly BookingSource $source = BookingSource::CLINIC,
        /**
         * The slot hold this booking is redeeming, if the caller took one.
         * A hold blocks everyone except the token that owns it.
         */
        public readonly ?string $holdToken = null,
    ) {}

    /**
     * Builds the DTO from a validated request body.
     *
     * `source` is deliberately absent. It is the one field a client must not
     * be able to set: accepting it would let any API caller label their own
     * booking as a patient's self-booking, which is exactly the claim the
     * public page's phone verification exists to establish. Callers that mean
     * something other than CLINIC pass it to the constructor themselves.
     *
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
            startTime: isset($validated['start_time']) ? substr((string) $validated['start_time'], 0, 5) : null,
            bookingKind: BookingKind::from((string) ($validated['booking_kind'] ?? BookingKind::NORMAL->value)),
            patientLocation: isset($validated['patient_location'])
                ? PatientLocation::from((string) $validated['patient_location'])
                : null,
            notes: isset($validated['notes']) ? trim((string) $validated['notes']) : null,
            updatePatientName: (bool) ($validated['update_patient_name'] ?? false),
            rebookingForBookingId: isset($validated['rebooking_for_booking_id'])
                ? (int) $validated['rebooking_for_booking_id']
                : null,
            holdToken: isset($validated['hold_token'])
                ? (string) $validated['hold_token']
                : null,
        );
    }
}
