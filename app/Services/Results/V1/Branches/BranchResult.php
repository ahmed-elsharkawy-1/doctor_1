<?php

namespace App\Services\Results\V1\Branches;

use App\Models\Branch;
use App\Services\Results\ServiceResult;
use App\Support\Wire;
use Illuminate\Support\Collection;

/**
 * A branch as the apps show it. Address, pin and phone are what patients
 * see: the branch's own, or the clinic's where the branch has none.
 */
final class BranchResult extends ServiceResult
{
    public function __construct(private readonly Branch $branch) {}

    public function toArray(): array
    {
        $coordinates = $this->branch->coordinates();
        [$lat, $lng] = $coordinates === null ? [null, null] : array_map('floatval', explode(',', $coordinates));

        return [
            'id' => $this->branch->id,
            'name' => $this->branch->name,
            'address' => $this->branch->displayAddress(),
            'latitude' => $lat,
            'longitude' => $lng,
            'phone' => Wire::phone($this->branch->contactPhone()),
            // False when it uses the clinic's main booking number.
            'own_phone' => filled($this->branch->phone),
            'is_active' => $this->branch->is_active,
            // This branch's own list, under the clinic's switch (ELA-21).
            'waiting_list_enabled' => (bool) $this->branch->waiting_list_enabled,
        ];
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $branches): array
    {
        return $branches->map(fn (Branch $branch) => (new self($branch))->toArray())->values()->all();
    }
}
