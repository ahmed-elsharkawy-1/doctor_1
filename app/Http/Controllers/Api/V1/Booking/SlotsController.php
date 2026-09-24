<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Booking\SlotAvailabilityRequest;
use App\Services\Results\V1\Booking\SlotAvailabilityResult;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class SlotsController extends V1Controller
{
    public function __construct(private readonly SlotAvailabilityService $slots) {}

    public function __invoke(SlotAvailabilityRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);

        $visitType = $clinic->visitTypes()->whereKey($request->validated('visit_type_id'))->first();

        if ($visitType === null) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_NOT_FOUND,
                __('settings.visit_type.not_found'),
                http: 404,
            );
        }

        $availability = $this->slots->for(
            $clinic,
            Carbon::parse($request->validated('date'), $clinic->timezone),
            $visitType,
            // Everyone else's holds read as taken; this caller's own does not.
            holdToken: $request->validated('hold_token'),
        );

        return ApiResponse::success(
            (new SlotAvailabilityResult($availability))->toArray(),
            __('booking.slots_loaded'),
        );
    }
}
