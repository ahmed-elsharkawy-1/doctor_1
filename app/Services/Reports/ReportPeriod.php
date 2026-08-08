<?php

namespace App\Services\Reports;

use App\Models\Clinic;
use Illuminate\Support\Carbon;

/**
 * A reporting window plus the equivalent window before it — SPEC §5.5.
 *
 * The comparison is always **equal-length and equally elapsed**: on the 8th of
 * the month, "this month" covers 8 days and is compared against the first 8
 * days of last month, not the whole of it. Comparing a partial period against a
 * complete one is the classic way to make a healthy month look like a collapse.
 */
final class ReportPeriod
{
    private function __construct(
        public readonly string $key,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Carbon $previousFrom,
        public readonly Carbon $previousTo,
    ) {}

    public function label(): string
    {
        return __('reports.period.'.$this->key);
    }

    public static function today(Clinic $clinic): self
    {
        $today = self::todayIn($clinic);

        return new self(
            key: 'today',
            from: $today->copy(),
            to: $today->copy(),
            previousFrom: $today->copy()->subDay(),
            previousTo: $today->copy()->subDay(),
        );
    }

    /**
     * The business week starts Saturday (SPEC §5.7), which is not Carbon's
     * default, so it is set explicitly.
     */
    public static function thisWeek(Clinic $clinic): self
    {
        $today = self::todayIn($clinic);
        $start = $today->copy()->startOfWeek(Carbon::SATURDAY);
        $elapsed = $start->diffInDays($today);

        $previousStart = $start->copy()->subWeek();

        return new self(
            key: 'this_week',
            from: $start,
            to: $today->copy(),
            previousFrom: $previousStart->copy(),
            // Same number of days into the week, so a Tuesday is compared
            // against last week up to its Tuesday.
            previousTo: $previousStart->copy()->addDays($elapsed),
        );
    }

    public static function thisMonth(Clinic $clinic): self
    {
        $today = self::todayIn($clinic);
        $start = $today->copy()->startOfMonth();
        $elapsed = $start->diffInDays($today);

        $previousStart = $start->copy()->subMonthNoOverflow();

        return new self(
            key: 'this_month',
            from: $start,
            to: $today->copy(),
            previousFrom: $previousStart->copy(),
            // Clamped, so the 31st never spills into the following month.
            previousTo: min(
                $previousStart->copy()->addDays($elapsed),
                $previousStart->copy()->endOfMonth(),
            ),
        );
    }

    /**
     * An explicit range, used by the retention screen's period filter.
     *
     * The key is passed in so a named range such as `last_90_days` still
     * reports itself by that name rather than as an anonymous custom span.
     */
    public static function between(Carbon $from, Carbon $to, string $key = 'custom'): self
    {
        $length = $from->diffInDays($to);

        return new self(
            key: $key,
            from: $from->copy()->startOfDay(),
            to: $to->copy()->startOfDay(),
            previousFrom: $from->copy()->startOfDay()->subDays($length + 1),
            previousTo: $from->copy()->startOfDay()->subDay(),
        );
    }

    private static function todayIn(Clinic $clinic): Carbon
    {
        return Carbon::now($clinic->timezone)->startOfDay();
    }
}
