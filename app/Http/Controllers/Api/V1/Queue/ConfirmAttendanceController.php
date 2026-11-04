<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Services\V1\Queue\AttendanceConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "أكد الحضور بالتليفون" — the secretary called a patient who never answered
 * the WhatsApp reminder, and they said they are coming.
 */
class ConfirmAttendanceController extends AdvanceStatusController
{
    public function __invoke(Request $request, AttendanceConfirmationService $confirmations, int $booking): JsonResponse
    {
        $confirmed = $confirmations->confirm(
            $this->booking($request, $booking),
            $this->user($request),
        );

        return $this->respond($request, $confirmed, __('booking.attendance_confirmed'));
    }
}
