<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Requests\Api\V1\Queue\UpdateBookingStatusRequest;
use Illuminate\Http\JsonResponse;

class UpdateBookingStatusController extends AdvanceStatusController
{
    public function __invoke(UpdateBookingStatusRequest $request, int $booking): JsonResponse
    {
        $updated = $this->status->update(
            $this->booking($request, $booking),
            $request->target(),
        );

        return $this->respond($request, $updated, __('booking.status_updated'));
    }
}
