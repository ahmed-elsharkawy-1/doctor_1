<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Booking\BookingCalendarRequest;
use App\Services\Results\V1\Booking\BookingCalendarResult;
use App\Services\V1\Booking\BookingCalendarService;
use App\Services\V1\Queue\QueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class BookingCalendarController extends V1Controller
{
    public function __construct(
        private readonly BookingCalendarService $calendar,
        private readonly QueueService $queue,
    ) {}

    public function __invoke(BookingCalendarRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $user = $this->user($request);

        $from = $request->filled('from')
            ? Carbon::parse($request->validated('from'), $clinic->timezone)
            : null;
        $to = $request->filled('to')
            ? Carbon::parse($request->validated('to'), $clinic->timezone)
            : null;

        $result = $this->calendar->range($clinic, $from, $to, $request->status());

        return ApiResponse::success(
            (new BookingCalendarResult($result, $this->queue, $user->hasAbility('prices.view')))->toArray(),
            __('booking.calendar_loaded'),
        );
    }
}
