<?php

namespace App\Services\V1\Settings;

use App\DTOs\V1\Settings\VisitTypeData;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\VisitType;
use Illuminate\Support\Collection;

class VisitTypeService
{
    /**
     * @return Collection<int, VisitType>
     */
    public function list(Clinic $clinic, bool $includeHidden = false): Collection
    {
        return $clinic->visitTypes()
            ->when(! $includeHidden, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function create(Clinic $clinic, VisitTypeData $data): VisitType
    {
        $this->guardAgainstDuplicateName($clinic, $data->name);

        $visitType = $clinic->visitTypes()->create($data->toAttributes() + [
            'price' => $data->price ?? 0,
            'is_active' => true,
            'sort_order' => (int) $clinic->visitTypes()->max('sort_order') + 1,
        ]);

        $this->keepOneNewPatientType($clinic, $visitType);

        return $visitType->refresh();
    }

    public function update(Clinic $clinic, int $visitTypeId, VisitTypeData $data): VisitType
    {
        $visitType = $this->find($clinic, $visitTypeId);

        $this->guardAgainstDuplicateName($clinic, $data->name, $visitType->id);

        $visitType->update($data->toAttributes());

        $this->keepOneNewPatientType($clinic, $visitType);

        return $visitType->refresh();
    }

    /**
     * Exactly one visit type per clinic may be the "new concern" type —
     * flagging a second one moves the flag rather than duplicating it.
     */
    private function keepOneNewPatientType(Clinic $clinic, VisitType $visitType): void
    {
        if (! $visitType->is_new_patient_type) {
            return;
        }

        $clinic->visitTypes()
            ->whereKeyNot($visitType->id)
            ->where('is_new_patient_type', true)
            ->update(['is_new_patient_type' => false]);
    }

    /**
     * Hides rather than deletes — historical bookings point at this row and
     * carry its snapshotted price and duration (SPEC §3.2).
     */
    public function hide(Clinic $clinic, int $visitTypeId): VisitType
    {
        $visitType = $this->find($clinic, $visitTypeId);

        $remaining = $clinic->visitTypes()
            ->where('is_active', true)
            ->whereKeyNot($visitType->id)
            ->count();

        if ($visitType->is_active && $remaining === 0) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_LAST_ACTIVE,
                __('settings.visit_type.last_active'),
            );
        }

        $visitType->hide();

        return $visitType->refresh();
    }

    public function find(Clinic $clinic, int $visitTypeId): VisitType
    {
        $visitType = $clinic->visitTypes()->whereKey($visitTypeId)->first();

        if ($visitType === null) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_NOT_FOUND,
                __('settings.visit_type.not_found'),
                http: 404,
            );
        }

        return $visitType;
    }

    /**
     * Two active types with the same name would make the booking screen
     * ambiguous. A hidden type does not block the name.
     */
    private function guardAgainstDuplicateName(Clinic $clinic, string $name, ?int $ignoreId = null): void
    {
        $exists = $clinic->visitTypes()
            ->where('is_active', true)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_DUPLICATE_NAME,
                __('settings.visit_type.duplicate_name'),
            );
        }
    }
}
