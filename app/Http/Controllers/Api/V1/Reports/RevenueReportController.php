<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Reports\ClinicReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueReportController extends V1Controller
{
    public function __construct(private readonly ClinicReportService $reports) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->reports->revenue($this->clinic($request));

        return ApiResponse::success($result->toArray(), __('reports.revenue_loaded'));
    }
}
