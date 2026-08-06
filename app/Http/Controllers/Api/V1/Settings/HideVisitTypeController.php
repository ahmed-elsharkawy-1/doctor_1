<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Settings\VisitTypeResult;
use App\Services\V1\Settings\VisitTypeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HideVisitTypeController extends V1Controller
{
    public function __construct(private readonly VisitTypeService $visitTypeService) {}

    public function __invoke(Request $request, int $visitType): JsonResponse
    {
        $hidden = $this->visitTypeService->hide($this->clinic($request), $visitType);

        return ApiResponse::success(
            (new VisitTypeResult($hidden, $this->user($request)->hasAbility('prices.view')))->toArray(),
            __('settings.visit_type.hidden'),
        );
    }
}
