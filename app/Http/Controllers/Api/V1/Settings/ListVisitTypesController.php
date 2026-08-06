<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Settings\VisitTypeResult;
use App\Services\V1\Settings\VisitTypeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListVisitTypesController extends V1Controller
{
    public function __construct(private readonly VisitTypeService $visitTypeService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $visitTypes = $this->visitTypeService->list(
            $this->clinic($request),
            includeHidden: $request->boolean('include_hidden'),
        );

        return ApiResponse::success(
            ['items' => VisitTypeResult::collection($visitTypes, $user->hasAbility('prices.view'))],
            __('settings.visit_type.list_loaded'),
        );
    }
}
