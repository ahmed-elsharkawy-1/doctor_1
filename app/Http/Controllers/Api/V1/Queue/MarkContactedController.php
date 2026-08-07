<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Queue\PostponeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "تم الاتصال" — ticks a row on the call list so the secretary keeps her place.
 */
class MarkContactedController extends V1Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly PostponeService $postpone,
    ) {}

    public function __invoke(Request $request, int $booking): JsonResponse
    {
        $marked = $this->postpone->markContacted(
            $this->bookings->find($this->clinic($request), $booking),
        );

        return ApiResponse::success(
            ['booking_id' => $marked->id, 'contacted_at' => $marked->contacted_at?->toAtomString()],
            __('booking.marked_contacted'),
        );
    }
}
