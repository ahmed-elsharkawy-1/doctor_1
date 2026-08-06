<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Settings\HolidayService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeleteHolidayController extends V1Controller
{
    public function __construct(private readonly HolidayService $holidayService) {}

    public function __invoke(Request $request, int $holiday): JsonResponse
    {
        $this->holidayService->delete($this->clinic($request), $holiday);

        return ApiResponse::success(null, __('settings.holiday.deleted'));
    }
}
