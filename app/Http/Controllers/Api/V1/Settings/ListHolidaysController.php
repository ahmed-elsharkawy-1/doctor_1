<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Settings\HolidayResult;
use App\Services\V1\Settings\HolidayService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListHolidaysController extends V1Controller
{
    public function __construct(private readonly HolidayService $holidayService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $holidays = $this->holidayService->list(
            $this->clinic($request),
            includePast: $request->boolean('include_past'),
        );

        return ApiResponse::success(
            ['items' => HolidayResult::collection($holidays)],
            __('settings.holiday.list_loaded'),
        );
    }
}
