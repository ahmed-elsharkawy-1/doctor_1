<?php

namespace App\Services\Results\V1\Team;

use App\Models\Clinic;
use App\Models\User;
use App\Services\Results\ServiceResult;
use Illuminate\Support\Collection;

final class TeamMemberResult extends ServiceResult
{
    public function __construct(private readonly User $member, private readonly Clinic $clinic) {}

    public function toArray(): array
    {
        $owner = $this->member->isOwner();

        return [
            'id' => $this->member->id,
            'name' => $this->member->name,
            'is_owner' => $owner,
            // The doctor: every branch, every permission, implicitly.
            'branch_ids' => $owner
                ? $this->clinic->branches()->pluck('id')->sort()->values()->all()
                : $this->member->branches->where('clinic_id', $this->clinic->id)->pluck('id')->sort()->values()->all(),
            'permissions' => $owner ? [] : array_values($this->member->permissions ?? []),
        ];
    }

    /**
     * @param  Collection<int, User>  $members
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $members, Clinic $clinic): array
    {
        return $members->map(fn (User $m) => (new self($m, $clinic))->toArray())->values()->all();
    }
}
