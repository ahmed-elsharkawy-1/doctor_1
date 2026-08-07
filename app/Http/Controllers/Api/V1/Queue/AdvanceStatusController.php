<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Models\Booking;
use App\Services\Results\V1\Queue\QueueItemResult;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Queue\BookingStatusService;
use App\Services\V1\Queue\QueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the four queue transitions. Each concrete controller
 * stays a single action, which is what the app calls after its confirm dialog.
 */
abstract class AdvanceStatusController extends V1Controller
{
    public function __construct(
        protected readonly BookingService $bookings,
        protected readonly BookingStatusService $status,
        protected readonly QueueService $queue,
    ) {}

    protected function respond(Request $request, Booking $booking, string $message): JsonResponse
    {
        $booking->load(['patient', 'visitType']);

        $result = new QueueItemResult(
            booking: $booking,
            queuePosition: null,
            availableActions: $this->queue->availableActions($booking),
            withPrice: $this->user($request)->hasAbility('prices.view'),
        );

        return ApiResponse::success($result->toArray(), $message);
    }

    protected function booking(Request $request, int $bookingId): Booking
    {
        return $this->bookings->find($this->clinic($request), $bookingId);
    }
}
