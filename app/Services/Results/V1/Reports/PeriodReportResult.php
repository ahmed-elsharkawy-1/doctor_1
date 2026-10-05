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
     * @param  array{count: int, income: float, previous_count: int, previous_income: float, previous_from: string, previous_to: string, change_percent: ?float, direction: string}  $completed
     * @param  list<array{name: string, count: int, income: float}>  $byVisitType
     * @param  array{done: int, no_show: int, cancelled: int, total: int, no_show_rate: ?float}  $outcomes
     * @param  array{new_count: int, returning_count: int, new: list<array{name: string, phone: ?string, visit_type: ?string}>}  $patients
     * @param  list<array{date: string, count: int, income: float, kind: string, note: ?string}>  $daily  empty for a single day
     * @param  int|null  $nextDayBookings  only for a single day
     * @param  array{kind: string, note: ?string}|null  $dayStatus  only for a single day: open, closed or holiday
     * @param  list<array{from: string, to: string, count: int, income: float}>  $weekly  only for a month: its business weeks
     */
    public function __construct(
        public readonly ReportPeriod $period,
        public readonly array $completed,
        public readonly array $byVisitType,
        public readonly array $outcomes,
        public readonly array $patients,
        public readonly array $daily,
        public readonly ?int $nextDayBookings,
        public readonly ?array $dayStatus = null,
        public readonly array $weekly = [],
    ) {}
}
