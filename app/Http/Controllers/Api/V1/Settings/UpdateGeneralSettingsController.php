<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\DTOs\V1\Settings\GeneralSettingsData;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Settings\UpdateGeneralSettingsRequest;
use App\Services\Results\V1\Settings\ClinicSettingsResult;
use App\Services\V1\Settings\ClinicSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateGeneralSettingsController extends V1Controller
{
    public function __construct(private readonly ClinicSettingsService $settingsService) {}

    public function __invoke(UpdateGeneralSettingsRequest $request): JsonResponse
    {
        $clinic = $this->settingsService->updateGeneral(
            $this->clinic($request),
            GeneralSettingsData::fromArray($request->validated()),
        );

        return ApiResponse::success(
            (new ClinicSettingsResult($clinic))->toArray(),
            __('settings.general_updated'),
        );
    }
}
