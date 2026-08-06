<?php

namespace App\Services\V1\Patients;

use App\Actions\Patient\GeneratePatientCodeAction;
use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Patient;
use App\Support\PhoneNumber;
use InvalidArgumentException;

class PatientService
{
    public function __construct(private readonly GeneratePatientCodeAction $generateCode) {}

    /**
     * Patients are matched on normalised phone alone, never on name — a typo
     * in the name would otherwise create a duplicate record and split the
     * visit history (SPEC §5.3).
     */
    public function findByPhone(Clinic $clinic, PhoneNumber $phone): ?Patient
    {
        return $clinic->patients()->where('phone', $phone->e164)->first();
    }

    public function parsePhone(Clinic $clinic, string $input): PhoneNumber
    {
        try {
            return PhoneNumber::parse($input, $clinic->country_code);
        } catch (InvalidArgumentException) {
            throw ApiException::make(
                ApiErrorCode::INVALID_PHONE_NUMBER,
                __('patient.invalid_phone'),
                details: ['phone' => $input],
                http: 422,
            );
        }
    }

    /**
     * Reuses the existing record when the phone is known, so the ID code and
     * the visit history stay with the patient.
     */
    public function findOrCreate(
        Clinic $clinic,
        string $name,
        PhoneNumber $phone,
        bool $updateName = false,
    ): Patient {
        $patient = $this->findByPhone($clinic, $phone);

        if ($patient === null) {
            return $clinic->patients()->create([
                'code' => $this->generateCode->execute($clinic, $name, $phone),
                'name' => trim($name),
                'phone' => $phone->e164,
            ]);
        }

        // The code never changes, only the display name — and only when the
        // secretary confirmed she meant to correct it.
        if ($updateName && trim($name) !== '' && trim($name) !== $patient->name) {
            $patient->update(['name' => trim($name)]);
        }

        return $patient;
    }

    /**
     * The last visit type actually used, ignoring cancellations. Drives the
     * mismatch warning.
     */
    public function lastVisit(Patient $patient): ?Booking
    {
        return $patient->bookings()
            ->whereIn('status', BookingStatus::occupyingSlot())
            ->with('visitType')
            ->latest('start_at')
            ->first();
    }

    public function visitsCount(Patient $patient): int
    {
        return $patient->bookings()
            ->whereIn('status', BookingStatus::occupyingSlot())
            ->count();
    }

    /**
     * A returning patient is one who has been booked here before at all —
     * not only one who completed a visit. Someone booked yesterday and seen
     * today is still not a new patient.
     */
    public function isReturning(Patient $patient): bool
    {
        return $this->visitsCount($patient) > 0;
    }
}
