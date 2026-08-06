<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Settings\ScheduleDayResult;
use App\Services\V1\Settings\ScheduleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetScheduleController extends V1Controller
{
    public function __construct(private readonly ScheduleService $scheduleService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $week = $this->scheduleService->week($this->clinic($request));

        return ApiResponse::success(
            ['days' => ScheduleDayResult::collection($week)],
            __('settings.schedule.loaded'),
        );
    }
}
