<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\ApiErrorCode;
use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Settings\UpdateScheduleDayRequest;
use App\Services\Results\V1\Settings\ScheduleDayResult;
use App\Services\V1\Settings\ScheduleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateScheduleDayController extends V1Controller
{
    public function __construct(private readonly ScheduleService $scheduleService) {}

    public function __invoke(UpdateScheduleDayRequest $request, int $day): JsonResponse
    {
        $dayOfWeek = DayOfWeek::tryFrom($day);

        if ($dayOfWeek === null) {
            throw ApiException::make(
                ApiErrorCode::SCHEDULE_DAY_NOT_FOUND,
                __('settings.schedule.day_not_found'),
                http: 404,
            );
        }

        $schedule = $this->scheduleService->updateDay(
            $this->clinic($request),
            ScheduleDayData::fromArray($dayOfWeek, $request->validated()),
        );

        return ApiResponse::success(
            (new ScheduleDayResult($schedule))->toArray(),
            __('settings.schedule.updated'),
        );
    }
}
