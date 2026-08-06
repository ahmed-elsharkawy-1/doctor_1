<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Settings\StoreHolidayRequest;
use App\Services\Results\V1\Settings\HolidayResult;
use App\Services\V1\Settings\HolidayService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CreateHolidayController extends V1Controller
{
    public function __construct(private readonly HolidayService $holidayService) {}

    public function __invoke(StoreHolidayRequest $request): JsonResponse
    {
        $holiday = $this->holidayService->create(
            $this->clinic($request),
            $request->validated('date'),
            $request->validated('note'),
            force: $request->boolean('force'),
        );

        return ApiResponse::created(
            (new HolidayResult($holiday))->toArray(),
            __('settings.holiday.created'),
        );
    }
}
