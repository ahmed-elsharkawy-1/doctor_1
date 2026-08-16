<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Queue\PostponeRequest;
use App\Services\Results\V1\Queue\RebookingListResult;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Queue\PostponeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Cancels the affected bookings and returns the call list to work through.
 * WhatsApp template broadcasts are handled by the messaging endpoints.
 */
class PostponeController extends V1Controller
{
    public function __construct(
        private readonly PostponeService $postpone,
        private readonly SlotAvailabilityService $slots,
    ) {}

    public function __invoke(PostponeRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);

        $date = $request->filled('date')
            ? Carbon::parse($request->validated('date'), $clinic->timezone)->startOfDay()
            : $this->slots->today($clinic);

        $postponed = $this->postpone->postpone($clinic, $date, $request->bookingIds());

        return ApiResponse::success(
            (new RebookingListResult($postponed))->toArray()
                + ['postponed_count' => $postponed->count()],
            __('booking.postponed', ['count' => $postponed->count()]),
        );
    }
}
