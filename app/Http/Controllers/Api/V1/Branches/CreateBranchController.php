<?php

namespace App\Http\Controllers\Api\V1\Branches;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Branches\SaveBranchRequest;
use App\Services\Results\V1\Branches\BranchResult;
use App\Services\V1\Branches\BranchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CreateBranchController extends V1Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function __invoke(SaveBranchRequest $request): JsonResponse
    {
        $branch = $this->branches->create($this->clinic($request), $request->branchData());

        return ApiResponse::created((new BranchResult($branch))->toArray(), __('settings.branch.created'));
    }
}
