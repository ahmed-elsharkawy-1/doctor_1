<?php

namespace App\Services\V1\Branches;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which branches a report reads (ELA-22, Phase 8): one the account may use,
 * or "all branches", the default, which is every one of hers.
 *
 * The answer is a list of branch ids, or null for "the whole clinic": what
 * an owner's "all branches" is, and what every report read before branches,
 * so a clinic with one branch runs the very same queries as it did.
 */
class BranchScope
{
    /** @return list<int>|null */
    public function resolve(User $user, Clinic $clinic, ?int $branchId = null): ?array
    {
        $mine = $user->accessibleBranches($clinic);

        if ($branchId !== null) {
            if (! $mine->contains('id', $branchId)) {
                throw ApiException::make(ApiErrorCode::BRANCH_NOT_FOUND, __('settings.branch.not_found'), http: 404);
            }

            return [$branchId];
        }

        return $this->readsWholeClinic($user, $clinic)
            ? null
            : $mine->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Whether this account reads the whole clinic: the doctor's own report
     * (the web page and the WhatsApp one) is doctor-wide, so it is for those
     * who may see every branch.
     */
    public function readsWholeClinic(User $user, Clinic $clinic): bool
    {
        return $user->accessibleBranches($clinic)->count() === $clinic->branches()->count();
    }

    /**
     * The branches a report screen offers to choose between: none unless
     * there is a choice.
     *
     * @return Collection<int, Branch>
     */
    public function choices(User $user, Clinic $clinic): Collection
    {
        $mine = $user->accessibleBranches($clinic);

        return app(BranchService::class)->canAdd() && $mine->count() > 1 ? $mine : collect();
    }
}
