<?php

namespace App\Http\Controllers\Api\V1\Branches;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Branches\BranchResult;
use App\Services\V1\Branches\BranchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST branches/{branch}/deactivate and branches/{branch}/activate. The route
 * says which; deactivating never cancels a booking (ELA-16).
 */
class SetBranchActiveController extends V1Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function __invoke(Request $request, int $branch): JsonResponse
    {
        $found = $this->branches->find($this->clinic($request), $branch, $this->user($request));
        $activate = $request->route()?->getName() === 'api.v1.branches.activate';

        $result = $activate ? $this->branches->activate($found) : $this->branches->deactivate($found);

        return ApiResponse::success(
            (new BranchResult($result))->toArray(),
            __($activate ? 'settings.branch.activated' : 'settings.branch.deactivated'),
        );
    }
}
