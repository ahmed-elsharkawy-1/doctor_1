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
     * One day, compared with the same weekday a week earlier. Clinic days are
     * not alike — a Saturday is compared with the last Saturday, not with the
     * Friday the clinic was closed.
     */
    public static function forDay(Carbon $date): self
    {
        $day = $date->copy()->startOfDay();

        return new self(
            key: 'day',
            from: $day->copy(),
            to: $day->copy(),
            previousFrom: $day->copy()->subWeek(),
            previousTo: $day->copy()->subWeek(),
        );
    }

    /**
     * The business week (Saturday to Friday) that starts on `$saturday`. A week
     * still running stops at `$today`, and is compared with the same number of
     * days of the week before.
     */
    public static function forWeek(Carbon $saturday, Carbon $today): self
    {
        $start = $saturday->copy()->startOfDay();
        $end = min($start->copy()->addDays(6), $today->copy()->startOfDay());
        $elapsed = $start->diffInDays($end);
        $previousStart = $start->copy()->subWeek();

        return new self(
            key: 'week',
            from: $start,
            to: $end,
            previousFrom: $previousStart,
            previousTo: $previousStart->copy()->addDays($elapsed),
        );
    }

    /**
     * The calendar month that starts on `$first`. A month still running stops
     * at `$today`, compared like thisMonth() does.
     */
    public static function forMonth(Carbon $first, Carbon $today): self
    {
        $start = $first->copy()->startOfMonth()->startOfDay();
        $end = min($start->copy()->endOfMonth()->startOfDay(), $today->copy()->startOfDay());
        $elapsed = $start->diffInDays($end);
        $previousStart = $start->copy()->subMonthNoOverflow();

        return new self(
            key: 'month',
            from: $start,
            to: $end,
            previousFrom: $previousStart,
            previousTo: min(
                $previousStart->copy()->addDays($elapsed),
                $previousStart->copy()->endOfMonth()->startOfDay(),
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
