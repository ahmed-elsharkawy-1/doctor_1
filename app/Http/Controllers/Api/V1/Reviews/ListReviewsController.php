<?php

namespace App\Http\Controllers\Api\V1\Reviews;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Reviews\ListReviewsRequest;
use App\Services\Results\V1\Reviews\ReviewListItemResult;
use App\Services\Results\V1\Reviews\ReviewSummaryResult;
use App\Services\V1\Reviews\ClinicReviewsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The reviews screen in one request: the summary card, and a page of the
 * list under the chosen filter.
 */
class ListReviewsController extends V1Controller
{
    public function __construct(private readonly ClinicReviewsService $reviews) {}

    public function __invoke(ListReviewsRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $page = $this->reviews->list($clinic, $request->rating(), $request->perPage());

        return ApiResponse::paginated(
            $page,
            ReviewListItemResult::collection($page->getCollection()),
            __('review.list_loaded'),
            ['summary' => (new ReviewSummaryResult($this->reviews->summary($clinic)))->toArray()],
        );
    }
}
