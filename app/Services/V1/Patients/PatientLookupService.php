<?php

namespace App\Services\V1\Patients;

use App\Models\Clinic;
use App\Services\Results\V1\Patients\PatientLookupResult;

/**
 * Powers the New Booking screen's live recognition of a returning patient,
 * and the visit-type mismatch warning (SPEC §4.3, §5.3).
 */
class PatientLookupService
{
    public function __construct(private readonly PatientService $patients) {}

    public function lookup(Clinic $clinic, string $phoneInput, ?string $typedName, ?int $visitTypeId): PatientLookupResult
    {
        $phone = $this->patients->parsePhone($clinic, $phoneInput);
        $patient = $this->patients->findByPhone($clinic, $phone);

        if ($patient === null) {
            return PatientLookupResult::notFound($phone);
        }

        $typedName = trim((string) $typedName);

        return new PatientLookupResult(
            phone: $phone,
            patient: $patient,
            visitsCount: $this->patients->visitsCount($patient),
            lastVisit: $this->patients->lastVisit($patient),
            // The secretary typed a different name for a phone we already
            // know — she either mistyped, or a family member is booking.
            nameConflict: $typedName !== '' && $typedName !== $patient->name,
            mismatchWarning: $this->hasVisitTypeMismatch($clinic, $patient, $visitTypeId),
        );
    }

    /**
     * Warns when a patient who has been here before is booked under the
     * clinic's "new concern" visit type.
     *
     * The flag lives on the visit type rather than on a name match, because
     * every clinic renames its own types.
     */
    private function hasVisitTypeMismatch(Clinic $clinic, $patient, ?int $visitTypeId): bool
    {
        if ($visitTypeId === null || ! $this->patients->isReturning($patient)) {
            return false;
        }

        return $clinic->visitTypes()
            ->whereKey($visitTypeId)
            ->where('is_new_patient_type', true)
            ->exists();
    }
}
