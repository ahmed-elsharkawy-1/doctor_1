<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\ResolvesReportingClinic;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\RevenueService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Today / this week / this month, each against the same span of the previous
 * period — SPEC §5.5.
 */
class RevenueOverview extends StatsOverviewWidget
{
    use ResolvesReportingClinic;

    protected ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $clinic = $this->reportingClinic();

        if ($clinic === null) {
            return [];
        }

        $revenue = app(RevenueService::class);

        return [
            $this->stat($revenue->forPeriod($clinic, ReportPeriod::today($clinic)), __('reports.revenue.today'), __('reports.compare.yesterday')),
            $this->stat($revenue->forPeriod($clinic, ReportPeriod::thisWeek($clinic)), __('reports.revenue.this_week'), __('reports.compare.last_week')),
            $this->stat($revenue->forPeriod($clinic, ReportPeriod::thisMonth($clinic)), __('reports.revenue.this_month'), __('reports.compare.last_month')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stat(array $data, string $label, string $comparisonLabel): Stat
    {
        $currency = __('messages.currency');

        $description = $data['change_percent'] === null
            ? $comparisonLabel.': '.number_format($data['previous_total'], 2).' '.$currency
            : sprintf('%s%s%% %s', $data['difference'] >= 0 ? '+' : '', $data['change_percent'], $comparisonLabel);

        return Stat::make($label, number_format($data['total'], 2).' '.$currency)
            ->description($description)
            ->descriptionIcon(match ($data['direction']) {
                'up' => 'heroicon-m-arrow-trending-up',
                'down' => 'heroicon-m-arrow-trending-down',
                default => 'heroicon-m-minus',
            })
            ->color(match ($data['direction']) {
                'up' => 'success',
                'down' => 'danger',
                default => 'gray',
            })
            ->extraAttributes(['title' => __('reports.revenue.completed_visits', ['count' => $data['count']])]);
    }
}
