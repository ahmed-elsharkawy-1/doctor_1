<?php

namespace App\Http\Controllers\Api\V1\Patients;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Patients\UpdateOnlineBookingRequest;
use App\Services\Results\V1\Patients\OnlineBookingStatusResult;
use App\Services\V1\Patients\OnlineBookingService;
use App\Services\V1\Patients\PatientSearchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateOnlineBookingController extends V1Controller
{
    public function __construct(
        private readonly PatientSearchService $patients,
        private readonly OnlineBookingService $onlineBooking,
    ) {}

    public function __invoke(UpdateOnlineBookingRequest $request, int $patient): JsonResponse
    {
        $found = $this->patients->find($this->clinic($request), $patient);

        $request->allowed()
            ? $this->onlineBooking->allow($found)
            : $this->onlineBooking->block($found);

        $result = new OnlineBookingStatusResult($found->refresh(), $this->onlineBooking);

        return ApiResponse::success(
            ['online_booking' => $result->toArray()],
            __($request->allowed() ? 'patient.online_booking.allowed_ok' : 'patient.online_booking.blocked_ok'),
        );
    }
}
