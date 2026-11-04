<?php

namespace App\Http\Controllers\Api\V1\WaitingList;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\WaitingList\WaitingListService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Takes a patient off the waiting list without booking them. Nothing is sent.
 * To book them instead, create the booking with `waiting_list_entry_id`.
 */
class RemoveWaitingListEntryController extends V1Controller
{
    public function __construct(private readonly WaitingListService $waitingList) {}

    public function __invoke(Request $request, int $entry): JsonResponse
    {
        $this->waitingList->remove($this->waitingList->find($this->clinic($request), $entry));

        return ApiResponse::success(null, __('waiting_list.removed_ok'));
    }
}
