<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\BookingKind;
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
            ->where(function ($query) use ($now, $today): void {
                $query
                    ->where('start_at', '>=', $now)
                    ->orWhere(function ($query) use ($today): void {
                        $query
                            ->where('booking_kind', BookingKind::EMERGENCY)
                            ->whereDate('visit_date', $today->toDateString());
                    });
            })
            ->whereNotIn('status', [BookingStatus::DONE, BookingStatus::CANCELLED, BookingStatus::NO_SHOW])
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
