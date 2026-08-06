<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\DTOs\V1\Booking\BookingData;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Booking\StoreBookingRequest;
use App\Services\Results\V1\Booking\BookingResult;
use App\Services\V1\Booking\BookingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CreateBookingController extends V1Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function __invoke(StoreBookingRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $booking = $this->bookings->create(
            $this->clinic($request),
            BookingData::fromArray($request->validated()),
            $user,
        );

        return ApiResponse::created(
            (new BookingResult($booking->load(['patient', 'visitType']), $user->hasAbility('prices.view')))->toArray(),
            __('booking.created'),
        );
    }
}
