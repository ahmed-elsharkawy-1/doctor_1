<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Queue\RebookingListResult;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Queue\QueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Who would be affected by a postponement — the multi-select list behind
 * "مريضات محددة".
 */
class PostponeCandidatesController extends V1Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly SlotAvailabilityService $slots,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);

        $date = $request->filled('date')
            ? Carbon::parse($request->query('date'), $clinic->timezone)->startOfDay()
            : $this->slots->today($clinic);

        $candidates = $this->queue->postponeCandidates($clinic, $date);

        return ApiResponse::success(
            (new RebookingListResult($candidates))->toArray(),
            __('booking.postpone_candidates_loaded'),
        );
    }
}
