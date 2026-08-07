<?php

namespace App\Http\Controllers\Api\V1\Patients;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Patients\PatientHistoryResult;
use App\Services\V1\Patients\PatientSearchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowPatientController extends V1Controller
{
    public function __construct(private readonly PatientSearchService $patients) {}

    public function __invoke(Request $request, int $patient): JsonResponse
    {
        $found = $this->patients->find($this->clinic($request), $patient);
        $history = $this->patients->history($found);

        $result = new PatientHistoryResult(
            patient: $found,
            history: $history,
            summary: $this->patients->summary($history),
            withPrice: $this->user($request)->hasAbility('prices.view'),
        );

        return ApiResponse::success($result->toArray(), __('patient.history_loaded'));
    }
}
