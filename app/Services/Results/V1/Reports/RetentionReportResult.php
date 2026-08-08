<?php

namespace App\Services\Results\V1\Reports;

use App\Services\Reports\ReportPeriod;
use App\Services\Results\ServiceResult;
use App\Support\Wire;

/**
 * Retention for the app's reports screen — SPEC §5.6.
 */
final class RetentionReportResult extends ServiceResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $availablePeriods
     */
    public function __construct(
        private readonly ReportPeriod $period,
        private readonly array $data,
        private readonly array $availablePeriods,
    ) {}

    public function toArray(): array
    {
        return [
            'period' => [
                'value' => $this->period->key,
                'display' => $this->period->label(),
                'from' => Wire::date($this->data['from']),
                'to' => Wire::date($this->data['to']),
            ],
            'available_periods' => array_map(
                static fn (string $value, string $display): array => compact('value', 'display'),
                array_keys($this->availablePeriods),
                array_values($this->availablePeriods),
            ),

            // Patients whose *first* completed visit fell in this period.
            'cohort_size' => $this->data['cohort_size'],
            'returned_count' => $this->data['returned_count'],
            // Null rather than 0% when the cohort is empty — there is no rate.
            'return_rate' => $this->data['return_rate'],

            // Seen once, long enough ago to call it.
            'first_visit_only_count' => $this->data['first_visit_only_count'],
            // Seen once, but still inside the window where she might return.
            'maturing_count' => $this->data['maturing_count'],
            'maturity_days' => $this->data['maturity_days'],

            'visits_in_period' => $this->data['visits_in_period'],
            'total_patients' => $this->data['total_patients'],
        ];
    }
}
