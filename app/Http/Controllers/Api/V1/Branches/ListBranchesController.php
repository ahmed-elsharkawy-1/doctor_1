<?php

namespace App\Http\Controllers\Api\V1\Branches;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Branches\BranchResult;
use App\Services\V1\Branches\BranchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListBranchesController extends V1Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success(
            [
                'branches' => BranchResult::collection($this->branches->list($this->clinic($request))),
                'can_add' => $this->branches->canAdd()
                    && $this->user($request)->hasAbility('branches.manage'),
            ],
            __('settings.branch.list_loaded'),
        );
    }
}
