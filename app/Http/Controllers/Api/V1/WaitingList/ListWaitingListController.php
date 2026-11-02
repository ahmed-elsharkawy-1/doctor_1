<?php

namespace App\Http\Controllers\Api\V1\WaitingList;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\WaitingList\ListWaitingListRequest;
use App\Services\Results\V1\WaitingList\WaitingListResult;
use App\Services\V1\WaitingList\WaitingListService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A day's waiting list, for the secretary to call down when a slot opens.
 */
class ListWaitingListController extends V1Controller
{
    public function __construct(private readonly WaitingListService $waitingList) {}

    public function __invoke(ListWaitingListRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $date = (string) ($request->validated('date') ?? $clinic->currentDay()->toDateString());

        return ApiResponse::success(
            (new WaitingListResult(
                $clinic,
                $date,
                $this->waitingList->forDay($clinic, $date),
                $this->waitingList->isJoinable($clinic, $date),
            ))->toArray(),
            __('waiting_list.loaded'),
        );
    }
}
