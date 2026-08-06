<?php

namespace App\Services\Results\V1\Patients;

use App\Models\Booking;
use App\Models\Patient;
use App\Services\Results\ServiceResult;
use App\Support\PhoneNumber;
use App\Support\Wire;

final class PatientLookupResult extends ServiceResult
{
    public function __construct(
        private readonly PhoneNumber $phone,
        private readonly ?Patient $patient = null,
        private readonly int $visitsCount = 0,
        private readonly ?Booking $lastVisit = null,
        private readonly bool $nameConflict = false,
        private readonly bool $mismatchWarning = false,
    ) {}

    public static function notFound(PhoneNumber $phone): self
    {
        return new self($phone);
    }

    public function toArray(): array
    {
        if ($this->patient === null) {
            return [
                'found' => false,
                'phone' => Wire::phone($this->phone),
                'patient' => null,
                'is_returning' => false,
                'name_conflict' => false,
                'visit_type_mismatch' => false,
                'last_visit' => null,
            ];
        }

        return [
            'found' => true,
            'phone' => Wire::phone($this->phone),
            'patient' => [
                'id' => $this->patient->id,
                'code' => $this->patient->code,
                'name' => $this->patient->name,
                'visits_count' => $this->visitsCount,
            ],
            'is_returning' => $this->visitsCount > 0,
            // The typed name differs from the one on file — offer to keep or
            // correct it before saving.
            'name_conflict' => $this->nameConflict,
            // A returning patient booked as a new concern (SPEC §4.3).
            'visit_type_mismatch' => $this->mismatchWarning,
            'last_visit' => $this->lastVisit === null ? null : [
                'date' => Wire::date($this->lastVisit->visit_date),
                'visit_type' => [
                    'id' => $this->lastVisit->visit_type_id,
                    'name' => $this->lastVisit->visitType?->name,
                ],
            ],
        ];
    }
}
