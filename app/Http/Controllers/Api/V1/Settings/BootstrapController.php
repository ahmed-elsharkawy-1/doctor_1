<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Settings\ClinicSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends V1Controller
{
    public function __construct(private readonly ClinicSettingsService $settingsService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->settingsService->bootstrap(
            $this->clinic($request),
            $this->user($request),
        );

        return ApiResponse::success($result->toArray(), __('settings.bootstrap_loaded'));
    }
}
