<?php

namespace App\Services\Results\V1\Reports;

use App\Services\Reports\ReportPeriod;

/**
 * What a doctor's report says about one day, week or month.
 *
 * Plain arrays, so the reports page, the WhatsApp summary and a future mobile
 * endpoint can each read the same numbers without re-deriving any of them.
 */
final class PeriodReportResult
{
    /**
     * @param  array{count: int, income: float, previous_count: int, previous_income: float}  $completed
     * @param  list<array{name: string, count: int, income: float}>  $byVisitType
     * @param  array{done: int, no_show: int, cancelled: int}  $outcomes
     * @param  array{new_count: int, returning_count: int, new: list<array{name: string, phone: ?string, visit_type: ?string}>}  $patients
     * @param  list<array{date: string, count: int, income: float}>  $daily  empty for a single day
     * @param  int|null  $nextDayBookings  only for a single day
     */
    public function __construct(
        public readonly ReportPeriod $period,
        public readonly array $completed,
        public readonly array $byVisitType,
        public readonly array $outcomes,
        public readonly array $patients,
        public readonly array $daily,
        public readonly ?int $nextDayBookings,
    ) {}
}
