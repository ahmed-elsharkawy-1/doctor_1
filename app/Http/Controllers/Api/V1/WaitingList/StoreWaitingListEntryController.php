<?php

namespace App\Http\Controllers\Api\V1\WaitingList;

use App\Enums\BookingSource;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\WaitingList\StoreWaitingListEntryRequest;
use App\Services\Results\V1\WaitingList\WaitingListEntryResult;
use App\Services\V1\Patients\PatientService;
use App\Services\V1\WaitingList\WaitingListService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Adds a patient to a day's waiting list — usually somebody who phoned for a
 * full day. They get the "joined the waiting list" message.
 */
class StoreWaitingListEntryController extends V1Controller
{
    public function __construct(
        private readonly WaitingListService $waitingList,
        private readonly PatientService $patients,
    ) {}

    public function __invoke(StoreWaitingListEntryRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);

        $patient = $request->filled('patient_id')
            ? $this->patients->findById($clinic, (int) $request->validated('patient_id'))
            : $this->patients->findOrCreate(
                $clinic,
                (string) $request->validated('patient_name'),
                $this->patients->parsePhone($clinic, (string) $request->validated('phone')),
            );

        $visitType = $request->filled('visit_type_id')
            ? $clinic->visitTypes()->active()->whereKey((int) $request->validated('visit_type_id'))->first()
            : null;

        $entry = $this->waitingList->join(
            $clinic,
            $patient,
            $visitType,
            (string) $request->validated('date'),
            BookingSource::CLINIC,
            $request->user(),
        );

        return ApiResponse::created(
            ['entry' => (new WaitingListEntryResult($entry, $this->waitingList->position($entry)))->toArray()],
            __('waiting_list.joined_ok'),
        );
    }
}
