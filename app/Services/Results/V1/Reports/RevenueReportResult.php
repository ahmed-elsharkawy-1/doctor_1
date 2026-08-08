<?php

namespace App\Services\Results\V1\Reports;

use App\Services\Results\ServiceResult;
use App\Support\Wire;
use Illuminate\Support\Carbon;

/**
 * Revenue for the app's reports screen — SPEC §5.5.
 *
 * Every period carries its own comparison against the same span of the
 * previous one, already computed, so the app only has to render it.
 */
final class RevenueReportResult extends ServiceResult
{
    /**
     * @param  array<string, array<string, mixed>>  $periods
     * @param  array<string, float>  $daily
     */
    public function __construct(
        private readonly array $periods,
        private readonly array $daily,
    ) {}

    public function toArray(): array
    {
        return [
            'currency' => __('messages.currency'),
            'periods' => [
                'today' => $this->period($this->periods['today'], __('reports.compare.yesterday')),
                'this_week' => $this->period($this->periods['this_week'], __('reports.compare.last_week')),
                'this_month' => $this->period($this->periods['this_month'], __('reports.compare.last_month')),
            ],
            // This month, day by day, with no gaps — for a trend line.
            'daily' => array_map(
                static fn (string $date, float $total): array => [
                    'date' => Wire::date($date),
                    'total' => Wire::money($total),
                ],
                array_keys($this->daily),
                array_values($this->daily),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function period(array $data, string $comparisonLabel): array
    {
        return [
            'total' => Wire::money($data['total']),
            'completed_visits' => $data['count'],
            'from' => Wire::date($data['from']),
            'to' => Wire::date($data['to']),
            'comparison' => [
                'label' => $comparisonLabel,
                'previous_total' => Wire::money($data['previous_total']),
                'previous_visits' => $data['previous_count'],
                'difference' => Wire::money($data['difference']),
                // Null when the previous period earned nothing — growth from
                // zero is not a percentage worth showing.
                'change_percent' => $data['change_percent'],
                'direction' => $data['direction'],
            ],
        ];
    }

    public static function dateOf(Carbon $date): string
    {
        return $date->toDateString();
    }
}
