<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Queue\SearchQueueRequest;
use App\Services\Results\V1\Queue\QueueSearchResult;
use App\Services\V1\Queue\QueueSearchService;
use App\Services\V1\Queue\QueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Today's queue, searched by name, code or phone, with each match's place.
 */
class SearchQueueController extends V1Controller
{
    public function __construct(
        private readonly QueueSearchService $search,
        private readonly QueueService $queue,
    ) {}

    public function __invoke(SearchQueueRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $found = $this->search->search($clinic, $request->validated('q'), $request->limit());

        return ApiResponse::success(
            (new QueueSearchResult(
                $clinic->currentDay(),
                $found['queue'],
                $found['matches'],
                $this->queue,
                $this->user($request)->hasAbility('prices.view'),
            ))->toArray(),
            __('booking.queue_search_done'),
        );
    }
}
