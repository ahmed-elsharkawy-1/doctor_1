<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Services\Results\V1\Booking\BookingCardResult;
use App\Services\V1\Booking\BookingCalendarService;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Queue\QueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HomeController extends V1Controller
{
    public function __construct(
        private readonly BookingCalendarService $calendar,
        private readonly SlotAvailabilityService $slots,
        private readonly QueueService $queue,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $user = $this->user($request);
        $today = $this->slots->today($clinic);
        $calendar = $this->calendar->range($clinic, $today, $today);
        $now = Carbon::now($clinic->timezone);

        $upcoming = $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->where('start_at', '>=', $now)
            ->whereNotIn('status', [BookingStatus::DONE, BookingStatus::CANCELLED, BookingStatus::NO_SHOW])
            ->orderBy('start_at')
            ->limit(5)
            ->get()
            ->map(fn ($booking) => (new BookingCardResult(
                $booking,
                $this->queue,
                $user->hasAbility('prices.view'),
            ))->toArray())
            ->values()
            ->all();

        return ApiResponse::success([
            'today' => [
                'date' => $today->toDateString(),
                'counts' => $calendar['days'][0]['counts'],
            ],
            'upcoming' => $upcoming,
        ], __('booking.home_loaded'));
    }
}
