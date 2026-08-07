<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\ResolvesReportingClinic;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\RetentionService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Return-visit rate and first-visit-only count for a chosen period — SPEC §5.6.
 */
class RetentionOverview extends StatsOverviewWidget
{
    use ResolvesReportingClinic;

    protected ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Set by the page's period filter.
     */
    public ?string $period = 'this_month';

    protected function getStats(): array
    {
        $clinic = $this->reportingClinic();

        if ($clinic === null) {
            return [];
        }

        $reportPeriod = match ($this->period) {
            'this_week' => ReportPeriod::thisWeek($clinic),
            'last_90_days' => ReportPeriod::between(
                now($clinic->timezone)->startOfDay()->subDays(89),
                now($clinic->timezone)->startOfDay(),
            ),
            'last_365_days' => ReportPeriod::between(
                now($clinic->timezone)->startOfDay()->subDays(364),
                now($clinic->timezone)->startOfDay(),
            ),
            default => ReportPeriod::thisMonth($clinic),
        };

        $data = app(RetentionService::class)->forPeriod($clinic, $reportPeriod);

        return [
            Stat::make(
                __('reports.retention.return_rate'),
                $data['return_rate'] === null ? '—' : $data['return_rate'].'%',
            )
                ->description(__('reports.retention.return_rate_hint', [
                    'returned' => $data['returned_count'],
                    'cohort' => $data['cohort_size'],
                ]))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($this->rateColour($data['return_rate'])),

            Stat::make(
                __('reports.retention.first_visit_only'),
                (string) $data['first_visit_only_count'],
            )
                ->description(__('reports.retention.first_visit_only_hint', ['days' => $data['maturity_days']]))
                ->descriptionIcon('heroicon-m-user-minus')
                ->color($data['first_visit_only_count'] > 0 ? 'warning' : 'success'),

            Stat::make(
                __('reports.retention.new_patients'),
                (string) $data['cohort_size'],
            )
                ->description(__('reports.retention.maturing_hint', ['count' => $data['maturing_count']]))
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('info'),

            Stat::make(
                __('reports.retention.visits_in_period'),
                (string) $data['visits_in_period'],
            )
                ->description(__('reports.retention.total_patients', ['count' => $data['total_patients']]))
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('gray'),
        ];
    }

    private function rateColour(?float $rate): string
    {
        return match (true) {
            $rate === null => 'gray',
            $rate >= 50 => 'success',
            $rate >= 25 => 'warning',
            default => 'danger',
        };
    }
}
