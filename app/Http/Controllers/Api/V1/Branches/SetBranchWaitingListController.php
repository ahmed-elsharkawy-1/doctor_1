<?php

namespace App\Http\Controllers\Api\V1\Branches;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Branches\BranchResult;
use App\Services\V1\Branches\BranchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUT branches/{branch}/waiting-list (ELA-21): the branch's own list on or
 * off. Off with patients waiting needs `confirm: true`; they stay on it.
 */
class SetBranchWaitingListController extends V1Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function __invoke(Request $request, int $branch): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $found = $this->branches->find($this->clinic($request), $branch, $this->user($request));
        $updated = $this->branches->setWaitingList($found, (bool) $validated['enabled'], (bool) ($validated['confirm'] ?? false));

        return ApiResponse::success(
            (new BranchResult($updated))->toArray(),
            __($updated->waiting_list_enabled ? 'settings.branch.waiting_list_on' : 'settings.branch.waiting_list_off'),
        );
    }
}
