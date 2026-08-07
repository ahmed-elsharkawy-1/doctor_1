<?php

namespace App\Http\Controllers\Api\V1\Queue;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "إنهاء الزيارة" — the visit is done. This is the status revenue counts.
 */
class CompleteController extends AdvanceStatusController
{
    public function __invoke(Request $request, int $booking): JsonResponse
    {
        $completed = $this->status->complete($this->booking($request, $booking));

        return $this->respond($request, $completed, __('booking.completed'));
    }
}
