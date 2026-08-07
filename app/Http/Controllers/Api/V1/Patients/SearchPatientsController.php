<?php

namespace App\Http\Controllers\Api\V1\Patients;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Patients\SearchPatientsRequest;
use App\Services\Results\V1\Patients\PatientListItemResult;
use App\Services\V1\Patients\PatientSearchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SearchPatientsController extends V1Controller
{
    public function __construct(private readonly PatientSearchService $patients) {}

    public function __invoke(SearchPatientsRequest $request): JsonResponse
    {
        $results = $this->patients->search(
            $this->clinic($request),
            $request->validated('q'),
            $request->perPage(),
        );

        return ApiResponse::paginated(
            $results,
            PatientListItemResult::collection($results->getCollection()),
            __('patient.search_done'),
        );
    }
}
