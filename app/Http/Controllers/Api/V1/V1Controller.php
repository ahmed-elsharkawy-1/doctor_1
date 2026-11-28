<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use App\Services\V1\Branches\BranchScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base for every V1 endpoint. Controllers are single-action (`__invoke`) and
 * stay thin — resolve the actor, call a service, hand the result to
 * ApiResponse.
 */
abstract class V1Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * The clinic every query is scoped to. Resolved from the token, never from
     * client input (SPEC §6.6).
     */
    /**
     * The branches a report reads: `?branch_id=`, or every branch of this
     * account's (Phase 8). Null is the whole clinic.
     *
     * @return list<int>|null
     */
    protected function reportScope(Request $request): ?array
    {
        $branchId = $request->validate(['branch_id' => ['nullable', 'integer']])['branch_id'] ?? null;

        return app(BranchScope::class)->resolve($this->user($request), $this->clinic($request), $branchId === null ? null : (int) $branchId);
    }

    protected function clinic(Request $request): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $request->attributes->get('clinic');

        return $clinic;
    }

    /**
     * The branch the call acts on: decided by ResolveClinic from the
     * account and its `X-Branch-Id`, never taken from the client as is
     * (ELA-17).
     */
    protected function branch(Request $request): Branch
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        return $branch;
    }
}
