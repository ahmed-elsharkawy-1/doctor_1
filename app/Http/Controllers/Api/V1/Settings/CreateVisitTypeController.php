<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\DTOs\V1\Settings\VisitTypeData;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Settings\StoreVisitTypeRequest;
use App\Services\Results\V1\Settings\VisitTypeResult;
use App\Services\V1\Settings\VisitTypeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CreateVisitTypeController extends V1Controller
{
    public function __construct(private readonly VisitTypeService $visitTypeService) {}

    public function __invoke(StoreVisitTypeRequest $request): JsonResponse
    {
        $canSetPrice = $request->canSetPrice();

        $visitType = $this->visitTypeService->create(
            $this->clinic($request),
            VisitTypeData::fromArray($request->validated(), $canSetPrice),
        );

        return ApiResponse::created(
            (new VisitTypeResult($visitType, $canSetPrice))->toArray(),
            __('settings.visit_type.created'),
        );
    }
}
