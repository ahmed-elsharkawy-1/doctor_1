<?php

namespace App\Services\V1\Reports;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\RetentionService;
use App\Services\Reports\RevenueService;
use App\Services\Results\V1\Reports\RetentionReportResult;
use App\Services\Results\V1\Reports\RevenueReportResult;
use Illuminate\Support\Carbon;

/**
 * The API face of the reporting maths. The calculations themselves live in
 * App\Services\Reports and are shared, so a future panel or export reads the
 * same numbers.
 */
class ClinicReportService
{
    public function __construct(
        private readonly RevenueService $revenue,
        private readonly RetentionService $retention,
    ) {}

    public function revenue(Clinic $clinic): RevenueReportResult
    {
        $month = ReportPeriod::thisMonth($clinic);

        return new RevenueReportResult(
            periods: [
                'today' => $this->revenue->forPeriod($clinic, ReportPeriod::today($clinic)),
                'this_week' => $this->revenue->forPeriod($clinic, ReportPeriod::thisWeek($clinic)),
                'this_month' => $this->revenue->forPeriod($clinic, $month),
            ],
            daily: $this->revenue->daily($clinic, $month->from, $month->to),
        );
    }

    public function retention(Clinic $clinic, ?string $period): RetentionReportResult
    {
        $period ??= config('clinic.retention.default_period');
        $resolved = $this->resolvePeriod($clinic, $period);

        return new RetentionReportResult(
            period: $resolved,
            data: $this->retention->forPeriod($clinic, $resolved),
            availablePeriods: $this->availablePeriods(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function availablePeriods(): array
    {
        return [
            'this_week' => __('reports.period.this_week'),
            'this_month' => __('reports.period.this_month'),
            'last_90_days' => __('reports.period.last_90_days'),
            'last_365_days' => __('reports.period.last_365_days'),
        ];
    }

    private function resolvePeriod(Clinic $clinic, string $period): ReportPeriod
    {
        $today = Carbon::now($clinic->timezone)->startOfDay();

        return match ($period) {
            'this_week' => ReportPeriod::thisWeek($clinic),
            'this_month' => ReportPeriod::thisMonth($clinic),
            'last_90_days' => ReportPeriod::between($today->copy()->subDays(89), $today, 'last_90_days'),
            'last_365_days' => ReportPeriod::between($today->copy()->subDays(364), $today, 'last_365_days'),
            default => throw ApiException::make(
                ApiErrorCode::UNKNOWN_REPORT_PERIOD,
                __('reports.unknown_period'),
                details: ['allowed' => array_keys($this->availablePeriods())],
                http: 422,
            ),
        };
    }
}
