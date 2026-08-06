<?php

namespace App\Services\Results\V1\Settings;

use App\Models\VisitType;
use App\Services\Results\ServiceResult;
use App\Support\Wire;
use Illuminate\Support\Collection;

/**
 * A visit type on the wire.
 *
 * Price is omitted entirely — not nulled — when the caller may not see it, so
 * a secretary's payload carries no pricing at all.
 */
final class VisitTypeResult extends ServiceResult
{
    public function __construct(
        private readonly VisitType $visitType,
        private readonly bool $withPrice,
    ) {}

    public function toArray(): array
    {
        $body = [
            'id' => $this->visitType->id,
            'name' => $this->visitType->name,
            'duration_minutes' => $this->visitType->duration_minutes,
            'is_active' => $this->visitType->is_active,
            // Booking a returning patient under this type triggers the
            // mismatch warning (SPEC 4.3).
            'is_new_patient_type' => $this->visitType->is_new_patient_type,
            'sort_order' => $this->visitType->sort_order,
        ];

        if ($this->withPrice) {
            $body['price'] = Wire::money($this->visitType->price);
            // Provisioned types start at zero; the owner has to price them
            // before revenue means anything.
            $body['needs_price'] = (float) $this->visitType->price <= 0;
        }

        return $body;
    }

    /**
     * @param  Collection<int, VisitType>  $visitTypes
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $visitTypes, bool $withPrice): array
    {
        return $visitTypes
            ->map(fn (VisitType $visitType) => (new self($visitType, $withPrice))->toArray())
            ->values()
            ->all();
    }
}
