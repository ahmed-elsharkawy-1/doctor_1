<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Booking\SlotHoldService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gives a held slot back — the screen was closed, or the booking abandoned.
 *
 * Addressed by the token rather than by an id, because the token is the only
 * thing that proves the caller owns the hold. It is never returned by any
 * listing, so knowing one means having been given it.
 *
 * Always answers 200. A hold that has already lapsed, been booked, or never
 * existed leaves nothing to free, and a client tidying up on its way out
 * should not have to care which of those happened.
 */
class ReleaseSlotHoldController extends V1Controller
{
    public function __construct(private readonly SlotHoldService $holds) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $hold = $this->holds->find($token);

        // Scoped like everything else: a token from another clinic frees
        // nothing here, whoever presents it.
        if ($hold !== null && $hold->clinic_id === $this->clinic($request)->id) {
            $this->holds->release($token);
        }

        return ApiResponse::success(null, __('booking.slot_released'));
    }
}
