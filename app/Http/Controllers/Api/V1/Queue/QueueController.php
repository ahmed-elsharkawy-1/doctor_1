<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Enums\DayOfWeek;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Queue\QueueResult;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Queue\QueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class QueueController extends V1Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly SlotAvailabilityService $slots,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $user = $this->user($request);

        $date = $request->filled('date')
            ? Carbon::parse($request->query('date'), $clinic->timezone)->startOfDay()
            : $this->slots->today($clinic);

        $queue = $this->queue->forDate($clinic, $date, $request->boolean('include_cancelled'));

        $isHoliday = $this->slots->isHoliday($clinic, $date);
        $schedule = $clinic->scheduleFor(DayOfWeek::fromDate($date));

        $result = new QueueResult(
            clinic: $clinic,
            date: $date,
            queue: $queue,
            service: $this->queue,
            isOpen: ! $isHoliday && (bool) $schedule?->is_open,
            isHoliday: $isHoliday,
            awaitingRebooking: $this->queue->awaitingRebookingCount($clinic),
            withPrice: $user->hasAbility('prices.view'),
        );

        return ApiResponse::success($result->toArray(), __('booking.queue_loaded'));
    }
}
