<?php

namespace App\Services\V1\Reports;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Clinic;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\RevenueService;
use App\Services\Results\V1\Reports\PeriodReportResult;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The doctor's report for one day, week or month.
 *
 * Income is RevenueService's — completed visits at the price frozen onto each
 * booking — so this report and the app's revenue screen can never disagree.
 * A patient is "new" when her first completed visit at the clinic falls inside
 * the period, the same rule the retention screen uses.
 */
class ClinicPeriodReportService
{
    public function __construct(private readonly RevenueService $revenue) {}

    public function for(Clinic $clinic, ReportPeriod $period): PeriodReportResult
    {
        $done = $this->completed($clinic, $period->from, $period->to);
        $previous = $this->revenue->totals($clinic, $period->previousFrom, $period->previousTo);
        $single = $period->from->isSameDay($period->to);
        $days = $this->openingDays($clinic, $period);
        $income = $this->sum($done);

        return new PeriodReportResult(
            period: $period,
            completed: [
                'count' => $done->count(),
                'income' => $income,
                'previous_count' => $previous['count'],
                'previous_income' => $previous['total'],
                'previous_from' => $period->previousFrom->toDateString(),
                'previous_to' => $period->previousTo->toDateString(),
                // Growth from nothing is not a percentage worth showing.
                'change_percent' => $previous['total'] > 0.0
                    ? round(($income - $previous['total']) / $previous['total'] * 100, 1)
                    : null,
                'direction' => match (true) {
                    $income > $previous['total'] => 'up',
                    $income < $previous['total'] => 'down',
                    default => 'flat',
                },
            ],
            byVisitType: $this->byVisitType($done),
            outcomes: $this->outcomes($clinic, $period),
            patients: $this->patients($clinic, $period, $done),
            daily: $single ? [] : $this->daily($period, $done, $days),
            nextDayBookings: $single ? $this->nextDayBookings($clinic, $period->to) : null,
            dayStatus: $single ? $days[$period->from->toDateString()] : null,
            weekly: $period->key === 'month' ? $this->weekly($period, $done) : [],
        );
    }

    /**
     * @return Collection<int, Booking>
     */
    private function completed(Clinic $clinic, Carbon $from, Carbon $to): Collection
    {
        return $this->revenue->completed($clinic, $from, $to)
            ->with(['patient', 'visitType'])
            ->orderBy('start_at')
            ->get();
    }

    /**
     * @param  Collection<int, Booking>  $done
     * @return list<array{name: string, count: int, income: float}>
     */
    private function byVisitType(Collection $done): array
    {
        return $done
            ->groupBy(fn (Booking $booking) => $booking->visitType?->name ?? '—')
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'count' => $group->count(),
                'income' => $this->sum($group),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @return array{done: int, no_show: int, cancelled: int, total: int, no_show_rate: ?float}
     */
    private function outcomes(Clinic $clinic, ReportPeriod $period): array
    {
        $counts = $clinic->bookings()
            ->whereBetween('visit_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $done = (int) ($counts[BookingStatus::DONE->value] ?? 0);
        $noShow = (int) ($counts[BookingStatus::NO_SHOW->value] ?? 0);

        return [
            'done' => $done,
            'no_show' => $noShow,
            'cancelled' => (int) ($counts[BookingStatus::CANCELLED->value] ?? 0),
            'total' => (int) $counts->sum(),
            // Out of those who were expected to come: a cancellation warned
            // the clinic, a no-show did not.
            'no_show_rate' => $done + $noShow > 0 ? round($noShow / ($done + $noShow) * 100, 1) : null,
        ];
    }

    /**
     * Whether the clinic was open each day: a dated holiday first, then the
     * weekly pattern. The pattern is today's — a clinic that changed its days
     * off sees older weeks through its current ones. Holidays are by date, so
     * those are always exact.
     *
     * @return array<string, array{kind: string, note: ?string}>
     */
    private function openingDays(Clinic $clinic, ReportPeriod $period): array
    {
        $holidays = $clinic->holidays()
            ->whereDate('date', '>=', $period->from->toDateString())
            ->whereDate('date', '<=', $period->to->toDateString())
            ->get()
            ->keyBy(fn ($holiday) => Carbon::parse($holiday->date)->toDateString());

        $open = $clinic->schedules()->pluck('is_open', 'day_of_week');
        $days = [];

        for ($date = $period->from->copy(); $date->lessThanOrEqualTo($period->to); $date->addDay()) {
            $key = $date->toDateString();
            $holiday = $holidays[$key] ?? null;

            $days[$key] = match (true) {
                $holiday !== null => ['kind' => 'holiday', 'note' => $holiday->note],
                ! ($open[DayOfWeek::fromDate($date)->value] ?? true) => ['kind' => 'closed', 'note' => null],
                default => ['kind' => 'open', 'note' => null],
            };
        }

        return $days;
    }

    /**
     * @param  Collection<int, Booking>  $done
     * @return array{new_count: int, returning_count: int, new: list<array{name: string, phone: ?string, visit_type: ?string}>}
     */
    private function patients(Clinic $clinic, ReportPeriod $period, Collection $done): array
    {
        $patientIds = $done->pluck('patient_id')->unique()->values();

        // Each patient's first completed visit ever — the retention screen's rule.
        $firstVisits = $clinic->bookings()
            ->where('status', BookingStatus::DONE)
            ->whereIn('patient_id', $patientIds)
            ->selectRaw('patient_id, MIN(visit_date) as first_visit_date')
            ->groupBy('patient_id')
            ->pluck('first_visit_date', 'patient_id');

        $isNew = fn (int $patientId): bool => Carbon::parse($firstVisits[$patientId])->greaterThanOrEqualTo($period->from);

        $new = $done
            ->filter(fn (Booking $booking) => $isNew($booking->patient_id))
            ->unique('patient_id')
            ->map(fn (Booking $booking) => [
                'name' => (string) $booking->patient?->name,
                'phone' => $this->phone($clinic, $booking->patient?->phone),
                'visit_type' => $booking->visitType?->name,
            ])
            ->values()
            ->all();

        return [
            'new_count' => count($new),
            'returning_count' => $patientIds->count() - count($new),
            'new' => $new,
        ];
    }

    /**
     * @param  Collection<int, Booking>  $done
     * @param  array<string, array{kind: string, note: ?string}>  $opening
     * @return list<array{date: string, count: int, income: float, kind: string, note: ?string}>
     */
    private function daily(ReportPeriod $period, Collection $done, array $opening): array
    {
        $byDate = $done->groupBy(fn (Booking $booking) => Carbon::parse($booking->visit_date)->toDateString());
        $days = [];

        for ($date = $period->from->copy(); $date->lessThanOrEqualTo($period->to); $date->addDay()) {
            $key = $date->toDateString();
            $group = $byDate[$key] ?? collect();
            $days[] = ['date' => $key, 'count' => $group->count(), 'income' => $this->sum($group)] + $opening[$key];
        }

        return $days;
    }

    /**
     * A month by business week (Saturday to Friday), the first and last
     * clipped to the month — four or five rows where days would be thirty.
     *
     * @param  Collection<int, Booking>  $done
     * @return list<array{from: string, to: string, count: int, income: float}>
     */
    private function weekly(ReportPeriod $period, Collection $done): array
    {
        $weeks = [];
        $from = $period->from->copy();

        while ($from->lessThanOrEqualTo($period->to)) {
            $to = min($from->copy()->endOfWeek(Carbon::FRIDAY)->startOfDay(), $period->to->copy());
            $inWeek = $done->filter(fn (Booking $booking) => Carbon::parse($booking->visit_date)->betweenIncluded($from, $to));

            $weeks[] = [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'count' => $inWeek->count(),
                'income' => $this->sum($inWeek),
            ];

            $from = $to->copy()->addDay();
        }

        return $weeks;
    }

    /** Still expected the day after — cancelled and no-shows are not coming. */
    private function nextDayBookings(Clinic $clinic, Carbon $day): int
    {
        return $clinic->bookings()
            ->whereDate('visit_date', $day->copy()->addDay()->toDateString())
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::NO_SHOW])
            ->count();
    }

    /** @param  Collection<int, Booking>  $bookings */
    private function sum(Collection $bookings): float
    {
        return round((float) $bookings->sum('price'), 2);
    }

    private function phone(Clinic $clinic, ?string $phone): ?string
    {
        return $phone === null ? null : (PhoneNumber::tryParse($phone, $clinic->country_code)?->national() ?? $phone);
    }
}
