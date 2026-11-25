<?php

namespace App\Http\Controllers\Api\V1\Branches;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Branches\SaveBranchRequest;
use App\Services\Results\V1\Branches\BranchResult;
use App\Services\V1\Branches\BranchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateBranchController extends V1Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function __invoke(SaveBranchRequest $request, int $branch): JsonResponse
    {
        $found = $this->branches->find($this->clinic($request), $branch);

        return ApiResponse::success(
            (new BranchResult($this->branches->update($found, $request->branchData())))->toArray(),
            __('settings.branch.updated'),
        );
    }
}
