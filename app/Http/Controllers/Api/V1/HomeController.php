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

        /*
         * Today, and only today. This once read `start_at >= now` with no
         * upper bound, so it swept in every future day and `take(5)` quietly
         * trimmed the evidence — a home screen showing all zeros above a
         * booking two days out. Another day's list is the calendar's job.
         *
         * `inQueue` rather than `pending` so the patient currently with the
         * doctor is still on it, and a booking whose time has passed without
         * anyone resolving it stays visible instead of disappearing at the
         * moment it starts needing attention.
         */
        $upcoming = $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->onDate($today->toDateString())
            ->whereIn('status', BookingStatus::inQueue())
            ->get()
            ->pipe(fn ($bookings) => $this->queue->sortBookings($bookings)->take(5))
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
