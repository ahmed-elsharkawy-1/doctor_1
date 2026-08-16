<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Requests\Api\V1\Queue\CancelBookingRequest;
use Illuminate\Http\JsonResponse;

/**
 * Patient cancellation. No-show is handled by the status endpoint.
 */
class CancelBookingController extends AdvanceStatusController
{
    public function __invoke(CancelBookingRequest $request, int $booking): JsonResponse
    {
        $cancelled = $this->status->cancel(
            $this->booking($request, $booking),
            $request->reason(),
        );

        return $this->respond($request, $cancelled, __('booking.cancelled_ok'));
    }
}
