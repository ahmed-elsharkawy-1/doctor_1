<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Reports\ReportWebLinkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A single-use link that opens the reports page in the app's WebView.
 */
class ReportWebLinkController extends V1Controller
{
    public function __construct(private readonly ReportWebLinkService $links) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success($this->links->issue($request->user()), __('reports.app.link_issued'));
    }
}
