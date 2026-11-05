<?php

namespace App\Http\Controllers\Api\V1\WaitingList;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\WaitingList\StoreWaitingListEntryRequest;
use App\Services\Results\V1\WaitingList\WaitingListEntryResult;
use App\Services\V1\WaitingList\WaitingListService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The secretary puts a patient on a full day's waiting list — someone who
 * phoned in. The patient is sent "you are on the waiting list", exactly as
 * when they join online.
 *
 * Already on that day's list: their place comes back with 200 and nothing is
 * sent again. Added: 201.
 */
class StoreWaitingListEntryController extends V1Controller
{
    public function __construct(private readonly WaitingListService $waitingList) {}

    public function __invoke(StoreWaitingListEntryRequest $request): JsonResponse
    {
        $patientId = $request->validated('patient_id');
        $visitTypeId = $request->validated('visit_type_id');

        $entry = $this->waitingList->addByClinic(
            $this->clinic($request),
            $request->user(),
            (string) $request->validated('date'),
            patientId: $patientId === null ? null : (int) $patientId,
            name: $request->validated('patient_name'),
            phone: $request->validated('phone'),
            visitTypeId: $visitTypeId === null ? null : (int) $visitTypeId,
        );

        return ApiResponse::success(
            (new WaitingListEntryResult($entry, $this->waitingList->position($entry)))->toArray(),
            $entry->wasRecentlyCreated ? __('waiting_list.added_ok') : __('waiting_list.already_on'),
            $entry->wasRecentlyCreated ? 201 : 200,
        );
    }
}
