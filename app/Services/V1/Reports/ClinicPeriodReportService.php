<?php

namespace App\Services\V1\Reports;

use App\Enums\BookingStatus;
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

        return new PeriodReportResult(
            period: $period,
            completed: [
                'count' => $done->count(),
                'income' => $this->sum($done),
                'previous_count' => $previous['count'],
                'previous_income' => $previous['total'],
            ],
            byVisitType: $this->byVisitType($done),
            outcomes: $this->outcomes($clinic, $period),
            patients: $this->patients($clinic, $period, $done),
            daily: $single ? [] : $this->daily($period, $done),
            nextDayBookings: $single ? $this->nextDayBookings($clinic, $period->to) : null,
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
     * @return array{done: int, no_show: int, cancelled: int}
     */
    private function outcomes(Clinic $clinic, ReportPeriod $period): array
    {
        $counts = $clinic->bookings()
            ->whereBetween('visit_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereIn('status', [BookingStatus::DONE, BookingStatus::NO_SHOW, BookingStatus::CANCELLED])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'done' => (int) ($counts[BookingStatus::DONE->value] ?? 0),
            'no_show' => (int) ($counts[BookingStatus::NO_SHOW->value] ?? 0),
            'cancelled' => (int) ($counts[BookingStatus::CANCELLED->value] ?? 0),
        ];
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
     * @return list<array{date: string, count: int, income: float}>
     */
    private function daily(ReportPeriod $period, Collection $done): array
    {
        $byDate = $done->groupBy(fn (Booking $booking) => Carbon::parse($booking->visit_date)->toDateString());
        $days = [];

        for ($date = $period->from->copy(); $date->lessThanOrEqualTo($period->to); $date->addDay()) {
            $group = $byDate[$date->toDateString()] ?? collect();
            $days[] = ['date' => $date->toDateString(), 'count' => $group->count(), 'income' => $this->sum($group)];
        }

        return $days;
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
