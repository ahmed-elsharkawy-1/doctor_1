<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Queue\RebookingListResult;
use App\Services\V1\Queue\QueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everyone still waiting for a new appointment after a postponement — reachable
 * any time from the home-screen banner, not only right after one.
 */
class RebookingListController extends V1Controller
{
    public function __construct(private readonly QueueService $queue) {}

    public function __invoke(Request $request): JsonResponse
    {
        $bookings = $this->queue->awaitingRebooking($this->clinic($request));

        return ApiResponse::success(
            (new RebookingListResult($bookings))->toArray(),
            __('booking.rebooking_list_loaded'),
        );
    }
}
