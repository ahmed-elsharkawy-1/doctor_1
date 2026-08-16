<?php

namespace App\Services\Results\V1\Patients;

use App\Models\Patient;
use App\Services\Results\ServiceResult;
use App\Support\PhoneNumber;
use App\Support\Wire;
use Illuminate\Support\Collection;

/**
 * A row in patient search.
 *
 * Phones are **masked** here — there is no call action on a search result. The
 * full number appears on the patient's own page and on queue cards, where
 * there is one (SPEC §4.4).
 */
final class PatientListItemResult extends ServiceResult
{
    public function __construct(private readonly Patient $patient) {}

    public function toArray(): array
    {
        $phone = $this->patient->phone ? PhoneNumber::tryParse($this->patient->phone) : null;

        return [
            'id' => $this->patient->id,
            'code' => $this->patient->code,
            'name' => $this->patient->name,
            'phone' => Wire::maskedPhone($phone),
            'visits_count' => (int) ($this->patient->visits_count ?? 0),
            'last_visit' => $this->patient->last_visit_date === null
                ? null
                : Wire::date($this->patient->last_visit_date),
        ];
    }

    /**
     * @param  Collection<int, Patient>  $patients
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $patients): array
    {
        return $patients
            ->map(fn (Patient $patient) => (new self($patient))->toArray())
            ->values()
            ->all();
    }
}
