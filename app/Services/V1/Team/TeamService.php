<?php

namespace App\Services\V1\Team;

use App\Enums\ApiErrorCode;
use App\Enums\StaffPermission;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Team (ELA-17): which branches each team member works at, and
 * which extra permissions the doctor handed them.
 *
 * Accounts themselves are still created by the operator in the panel; this
 * only shapes what an existing account may do. Changes apply on the
 * member's next tap, because every request resolves its branch afresh.
 */
class TeamService
{
    /**
     * The doctor first, then the team, by name.
     *
     * @return Collection<int, User>
     */
    public function members(Clinic $clinic): Collection
    {
        return $clinic->staff()
            ->where('role', UserRole::CLINIC)
            ->with('branches')
            ->orderByDesc('is_owner')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  list<int>  $branchIds  at least one, all of this clinic
     * @param  list<string>  $permissions  StaffPermission values
     */
    public function update(Clinic $clinic, int $memberId, array $branchIds, array $permissions): User
    {
        $member = $clinic->staff()->where('role', UserRole::CLINIC)->whereKey($memberId)->first();

        if ($member === null) {
            throw ApiException::make(ApiErrorCode::TEAM_MEMBER_NOT_FOUND, __('settings.team.not_found'), http: 404);
        }

        // The doctor works at every branch with every permission; there is
        // nothing here to take away.
        if ($member->isOwner()) {
            throw ApiException::make(ApiErrorCode::TEAM_MEMBER_IS_OWNER, __('settings.team.is_owner'), http: 409);
        }

        $branchIds = $clinic->branches()->whereKey(array_unique($branchIds))->pluck('id')->all();

        // Without a branch she couldn't open the app at all (ELA-17).
        if ($branchIds === []) {
            throw ApiException::make(ApiErrorCode::TEAM_BRANCH_REQUIRED, __('settings.team.branch_required'), http: 422);
        }

        $permissions = array_values(array_intersect(
            array_map(fn (StaffPermission $p) => $p->value, StaffPermission::cases()),
            $permissions,
        ));

        return DB::transaction(function () use ($clinic, $member, $branchIds, $permissions): User {
            // Only this clinic's branches change: an account in two clinics
            // keeps the other's as they were.
            $theirs = $member->branches()->where('branches.clinic_id', $clinic->id)->pluck('branches.id');
            $member->branches()->detach($theirs->diff($branchIds)->all());
            $member->branches()->syncWithoutDetaching($branchIds);
            $member->update(['permissions' => $permissions]);

            return $member->load('branches');
        });
    }
}
