<?php

namespace App\Services\V1\Reports;

use App\Models\Clinic;
use App\Services\Reports\ReportPeriod;
use Illuminate\Support\Carbon;

/**
 * Which days, weeks and months a doctor may open, and which one the morning
 * message is about.
 *
 * One rule for both on purpose: a link the message sends must always be one
 * the page will open. Everything is in the clinic's own timezone, and weeks
 * are the business week, Saturday to Friday (SPEC §5.7).
 *
 * - Days: yesterday and the five before it. Today is still happening.
 * - Weeks: this week so far, and last week.
 * - Months: this month so far, and the five before it.
 */
class ReportCalendar
{
    public const DAYS = 6;

    public const PAST_MONTHS = 5;

    /**
     * @return list<array{type: string, value: string, date: string, label: string}>
     */
    public function days(Clinic $clinic): array
    {
        $yesterday = $this->today($clinic)->subDay();
        $days = [];

        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $yesterday->copy()->subDays($i);
            $days[] = [
                'type' => 'day',
                'value' => $day->toDateString(),
                'date' => $day->toDateString(),
                'label' => $day->locale(app()->getLocale())->isoFormat('ddd D/M'),
            ];
        }

        return $days;
    }

    /**
     * @return list<array{type: string, value: string, label: string}>
     */
    public function periods(Clinic $clinic): array
    {
        $today = $this->today($clinic);
        $thisWeek = $today->copy()->startOfWeek(Carbon::SATURDAY);
        $thisMonth = $today->copy()->startOfMonth();

        $periods = [
            ['type' => 'week', 'value' => $thisWeek->toDateString(), 'label' => __('reports.calendar.this_week')],
            ['type' => 'week', 'value' => $thisWeek->copy()->subWeek()->toDateString(), 'label' => __('reports.calendar.last_week')],
            ['type' => 'month', 'value' => $thisMonth->format('Y-m'), 'label' => __('reports.calendar.this_month')],
        ];

        for ($i = 1; $i <= self::PAST_MONTHS; $i++) {
            $month = $thisMonth->copy()->subMonthsNoOverflow($i);
            $periods[] = [
                'type' => 'month',
                'value' => $month->format('Y-m'),
                'label' => $month->locale(app()->getLocale())->isoFormat('MMMM'),
            ];
        }

        return $periods;
    }

    /**
     * The period behind a link, or null when it is not one the doctor may open.
     */
    public function resolve(Clinic $clinic, string $type, string $value): ?ReportPeriod
    {
        $allowed = match ($type) {
            'day' => array_column($this->days($clinic), 'value'),
            'week', 'month' => array_column(array_filter($this->periods($clinic), fn ($p) => $p['type'] === $type), 'value'),
            default => [],
        };

        if (! in_array($value, $allowed, true)) {
            return null;
        }

        $today = $this->today($clinic);

        return match ($type) {
            'day' => ReportPeriod::forDay(Carbon::parse($value, $clinic->timezone)),
            'week' => ReportPeriod::forWeek(Carbon::parse($value, $clinic->timezone), $today),
            'month' => ReportPeriod::forMonth(Carbon::parse($value.'-01', $clinic->timezone), $today),
        };
    }

    /**
     * What this morning's message is about: last month on the 1st, last week
     * on Saturday, yesterday otherwise. The bigger period wins, so the doctor
     * never gets two reports in one morning.
     *
     * @return array{0: string, 1: string}
     */
    public function morning(Clinic $clinic): array
    {
        $today = $this->today($clinic);

        return match (true) {
            $today->day === 1 => ['month', $today->copy()->subMonthNoOverflow()->format('Y-m')],
            $today->dayOfWeek === Carbon::SATURDAY => ['week', $today->copy()->subWeek()->toDateString()],
            default => ['day', $today->copy()->subDay()->toDateString()],
        };
    }

    private function today(Clinic $clinic): Carbon
    {
        return Carbon::now($clinic->timezone)->startOfDay();
    }
}
