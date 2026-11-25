<?php

namespace App\Services\V1\Branches;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;

/**
 * Which branch a request acts on (ELA-17).
 *
 * The client may ask for one (the web session, or the mobile app's
 * `X-Branch-Id` header) but the server decides, as it does for the clinic
 * (SPEC §6.6). In order:
 *
 *   1. the branch asked for, if this account may use it;
 *   2. the branch it last used, if it still may;
 *   3. the first branch it may use.
 *
 * Null means it may use none: it was removed from every branch, and is
 * signed out with a message saying so.
 *
 * Runs on every request, so a change to someone's branches takes effect on
 * their next tap.
 */
class ActiveBranch
{
    /** Where the web app keeps the branch picked in this browser. */
    public const SESSION_KEY = 'app.branch_id';

    /** How the mobile app asks for a branch, and how it is told the answer. */
    public const HEADER = 'X-Branch-Id';

    public function resolve(User $user, Clinic $clinic, ?int $requested = null): ?Branch
    {
        $allowed = $user->accessibleBranches($clinic);

        foreach ([$requested, $user->last_branch_id] as $candidate) {
            if ($candidate !== null && ($branch = $allowed->firstWhere('id', $candidate)) !== null) {
                return $branch;
            }
        }

        return $allowed->first();
    }

    /**
     * What the apps open on next time.
     */
    public function remember(User $user, Branch $branch): void
    {
        if ($user->last_branch_id !== $branch->id) {
            $user->forceFill(['last_branch_id' => $branch->id])->saveQuietly();
        }
    }
}
