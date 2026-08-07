<?php

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Models\Clinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Retention — SPEC §5.6.
 *
 * Cohort-based: the cohort is the patients whose **first** completed visit
 * falls inside the period, and the rate is how many of them came back. A
 * simple "patients with 2+ visits ÷ all patients" would answer a different
 * question and drift as the clinic ages.
 *
 * The first-visit-only count deliberately excludes anyone whose only visit is
 * recent: a patient seen last week has not "never returned", she just has not
 * returned *yet*. Without that, the month-1 number is meaningless — which
 * matters because month 1 is exactly when the PRD wants a baseline.
 */
class RetentionService
{
    /**
     * @return array{
     *     cohort_size: int,
     *     returned_count: int,
     *     return_rate: ?float,
     *     first_visit_only_count: int,
     *     maturing_count: int,
     *     total_patients: int,
     *     visits_in_period: int,
     *     maturity_days: int,
     *     from: Carbon, to: Carbon
     * }
     */
    public function forPeriod(Clinic $clinic, ReportPeriod $period): array
    {
        $firstVisits = $this->firstVisitDates($clinic);
        $visitCounts = $this->completedVisitCounts($clinic);

        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        $cohort = $firstVisits->filter(
            fn (string $date) => $date >= $from && $date <= $to,
        );

        $returned = $cohort->filter(
            fn (string $date, int $patientId) => ($visitCounts[$patientId] ?? 0) >= 2,
        );

        $maturityDays = $clinic->first_visit_only_days;
        $cutoff = Carbon::now($clinic->timezone)->startOfDay()->subDays($maturityDays)->toDateString();

        // Seen once, and long enough ago that we can call it.
        $firstVisitOnly = $cohort->filter(
            fn (string $date, int $patientId) => ($visitCounts[$patientId] ?? 0) === 1 && $date <= $cutoff,
        );

        // Seen once, but still inside the window where she might come back.
        $maturing = $cohort->filter(
            fn (string $date, int $patientId) => ($visitCounts[$patientId] ?? 0) === 1 && $date > $cutoff,
        );

        return [
            'cohort_size' => $cohort->count(),
            'returned_count' => $returned->count(),
            'return_rate' => $cohort->isEmpty()
                ? null
                : round(($returned->count() / $cohort->count()) * 100, 1),
            'first_visit_only_count' => $firstVisitOnly->count(),
            'maturing_count' => $maturing->count(),
            'total_patients' => $clinic->patients()->count(),
            'visits_in_period' => $this->visitsBetween($clinic, $period->from, $period->to),
            'maturity_days' => $maturityDays,
            'from' => $period->from,
            'to' => $period->to,
        ];
    }

    /**
     * patient id => the date of her first completed visit.
     *
     * @return Collection<int, string>
     */
    private function firstVisitDates(Clinic $clinic): Collection
    {
        return $clinic->bookings()
            ->where('status', BookingStatus::DONE)
            ->selectRaw('patient_id, MIN(visit_date) as first_visit_date')
            ->groupBy('patient_id')
            ->pluck('first_visit_date', 'patient_id')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());
    }

    /**
     * patient id => how many completed visits she has, ever.
     *
     * @return Collection<int, int>
     */
    private function completedVisitCounts(Clinic $clinic): Collection
    {
        return $clinic->bookings()
            ->where('status', BookingStatus::DONE)
            ->selectRaw('patient_id, COUNT(*) as visits')
            ->groupBy('patient_id')
            ->pluck('visits', 'patient_id')
            ->map(fn ($count) => (int) $count);
    }

    private function visitsBetween(Clinic $clinic, Carbon $from, Carbon $to): int
    {
        return $clinic->bookings()
            ->where('status', BookingStatus::DONE)
            ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
            ->count();
    }
}
