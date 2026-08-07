<?php

namespace App\Http\Controllers\Api\V1\Queue;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "تسجيل الوصول" — the patient is physically in the clinic. This is the
 * moment that gives her a queue position.
 */
class ArriveController extends AdvanceStatusController
{
    public function __invoke(Request $request, int $booking): JsonResponse
    {
        $arrived = $this->status->arrive($this->booking($request, $booking));

        return $this->respond($request, $arrived, __('booking.arrived'));
    }
}
