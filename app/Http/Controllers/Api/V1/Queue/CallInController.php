<?php

namespace App\Http\Controllers\Api\V1\Queue;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "استدعاء للداخل" — the patient goes in to the doctor.
 */
class CallInController extends AdvanceStatusController
{
    public function __invoke(Request $request, int $booking): JsonResponse
    {
        $calledIn = $this->status->callIn($this->booking($request, $booking));

        return $this->respond($request, $calledIn, __('booking.called_in'));
    }
}
