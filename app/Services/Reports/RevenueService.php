<?php

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Clinic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Revenue — SPEC §5.5.
 *
 * Counts **completed visits only**, at the price snapshotted onto each booking.
 * Cancellations, no-shows and unfinished visits are worth nothing, and a later
 * price change never rewrites a past total.
 *
 * With fixed prices and no payment tracking in v1, this is *expected* revenue
 * from completed visits, not cash collected — the screen says so.
 */
class RevenueService
{
    /**
     * @return array{
     *     total: float, count: int,
     *     previous_total: float, previous_count: int,
     *     difference: float, change_percent: ?float, direction: string,
     *     from: Carbon, to: Carbon
     * }
     */
    public function forPeriod(Clinic $clinic, ReportPeriod $period): array
    {
        $current = $this->totals($clinic, $period->from, $period->to);
        $previous = $this->totals($clinic, $period->previousFrom, $period->previousTo);

        $difference = round($current['total'] - $previous['total'], 2);

        return [
            'total' => $current['total'],
            'count' => $current['count'],
            'previous_total' => $previous['total'],
            'previous_count' => $previous['count'],
            'difference' => $difference,
            'change_percent' => $this->changePercent($current['total'], $previous['total']),
            'direction' => match (true) {
                $difference > 0 => 'up',
                $difference < 0 => 'down',
                default => 'flat',
            },
            'from' => $period->from,
            'to' => $period->to,
        ];
    }

    /**
     * @return array{total: float, count: int}
     */
    public function totals(Clinic $clinic, Carbon $from, Carbon $to): array
    {
        $query = $this->completed($clinic, $from, $to);

        return [
            'total' => round((float) $query->clone()->sum('price'), 2),
            'count' => $query->clone()->count(),
        ];
    }

    /**
     * Daily totals across the window, for a trend line. Days with no completed
     * visits are present with a zero so the series has no gaps.
     *
     * @return array<string, float>
     */
    public function daily(Clinic $clinic, Carbon $from, Carbon $to): array
    {
        $totals = $this->completed($clinic, $from, $to)
            ->get(['visit_date', 'price'])
            ->groupBy(fn ($booking) => Carbon::parse($booking->visit_date)->toDateString())
            ->map(fn ($group) => round((float) $group->sum('price'), 2));

        $series = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $key = $date->toDateString();
            $series[$key] = $totals[$key] ?? 0.0;
        }

        return $series;
    }

    /**
     * @return Builder<Booking>
     */
    public function completed(Clinic $clinic, Carbon $from, Carbon $to): Builder
    {
        return $clinic->bookings()
            ->where('status', BookingStatus::DONE)
            ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
            ->getQuery();
    }

    private function changePercent(float $current, float $previous): ?float
    {
        // Growth from nothing is not a percentage worth showing.
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
