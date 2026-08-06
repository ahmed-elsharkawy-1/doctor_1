<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Booking\BookingResult;
use App\Services\V1\Booking\BookingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowBookingController extends V1Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function __invoke(Request $request, int $booking): JsonResponse
    {
        $user = $this->user($request);

        $found = $this->bookings->find($this->clinic($request), $booking);

        return ApiResponse::success(
            (new BookingResult($found, $user->hasAbility('prices.view')))->toArray(),
            __('booking.loaded'),
        );
    }
}
