<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Enums\BookingSource;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Booking\StoreSlotHoldRequest;
use App\Services\Results\V1\Booking\SlotHoldResult;
use App\Services\V1\Booking\SlotHoldService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Claims a slot the moment it is tapped, before the rest of the form is filled
 * in, so everybody else sees it go.
 *
 * The response carries a token. Send it back when booking, and the slot being
 * held is free to this caller and to nobody else; send it again when picking a
 * different time and the claim moves rather than multiplying.
 */
class CreateSlotHoldController extends V1Controller
{
    public function __construct(private readonly SlotHoldService $holds) {}

    public function __invoke(StoreSlotHoldRequest $request): JsonResponse
    {
        $hold = $this->holds->hold(
            $this->clinic($request),
            (int) $request->validated('visit_type_id'),
            (string) $request->validated('date'),
            (string) $request->validated('start_time'),
            // Staff, always. A patient's hold is taken by the public page,
            // which never reaches this endpoint.
            BookingSource::CLINIC,
            $this->user($request),
            $request->validated('token'),
        );

        return ApiResponse::created(
            (new SlotHoldResult($hold))->toArray(),
            __('booking.slot_held'),
        );
    }
}
