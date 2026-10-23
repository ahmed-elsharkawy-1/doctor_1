<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Queue\EmergencyRequest;
use App\Services\Results\V1\Queue\DelayResult;
use App\Services\V1\Queue\DelayService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * "تأخير طارئ": moves today's appointments later from the patient picked,
 * and messages everyone who moved.
 */
class EmergencyController extends V1Controller
{
    public function __construct(private readonly DelayService $delay) {}

    public function __invoke(EmergencyRequest $request): JsonResponse
    {
        $outcome = $this->delay->delay(
            $this->clinic($request),
            (int) $request->validated('booking_id'),
            (int) $request->validated('delay_minutes'),
        );

        return ApiResponse::success(
            (new DelayResult($outcome))->toArray(),
            trans_choice('booking.delay.delayed', max(1, $outcome->bookings->count())),
        );
    }
}
