<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Booking\PatientLookupRequest;
use App\Services\V1\Patients\PatientLookupService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PatientLookupController extends V1Controller
{
    public function __construct(private readonly PatientLookupService $lookup) {}

    public function __invoke(PatientLookupRequest $request): JsonResponse
    {
        $result = $this->lookup->lookup(
            $this->clinic($request),
            $request->validated('phone'),
            $request->validated('name'),
            $request->validated('visit_type_id'),
        );

        return ApiResponse::success($result->toArray(), __('patient.lookup_done'));
    }
}
