<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\DTOs\V1\Booking\BookingData;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Booking\UpdateBookingRequest;
use App\Services\Results\V1\Booking\BookingResult;
use App\Services\V1\Booking\BookingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateBookingController extends V1Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function __invoke(UpdateBookingRequest $request, int $booking): JsonResponse
    {
        $user = $this->user($request);

        $updated = $this->bookings->update(
            $this->clinic($request),
            $booking,
            BookingData::fromArray($request->validated()),
        );

        return ApiResponse::success(
            (new BookingResult($updated->load(['patient', 'visitType']), $user->hasAbility('prices.view')))->toArray(),
            __('booking.updated'),
        );
    }
}
