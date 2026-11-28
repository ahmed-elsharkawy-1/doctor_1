<?php

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Models\Clinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 *
 * At some branches only (Phase 8), the cohort is the patients whose first
 * visit with the doctor was at one of them, and one came back if she came
 * back at all, to any branch: the doctor kept her. Visits in the period and
 * the patient count are those branches' own.
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
    public function forPeriod(Clinic $clinic, ReportPeriod $period, ?array $branchIds = null): array
    {
        $firstVisits = $this->firstVisitDates($clinic, $branchIds);
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
            // At some branches: the patients seen there, so one seen at two
            // is each's, and the branches do not add up to the clinic.
            'total_patients' => $branchIds === null
                ? $clinic->patients()->count()
                : $clinic->patients()->whereHas('bookings', fn ($query) => $query
                    ->where('status', BookingStatus::DONE)
                    ->whereIn('branch_id', $branchIds))->count(),
            'visits_in_period' => $this->visitsBetween($clinic, $period->from, $period->to, $branchIds),
            'maturity_days' => $maturityDays,
            'from' => $period->from,
            'to' => $period->to,
        ];
    }

    /**
     * patient id => the date of her first completed visit — of those whose
     * first was at one of `$branchIds`, when given.
     *
     * @param  list<int>|null  $branchIds
     * @return Collection<int, string>
     */
    private function firstVisitDates(Clinic $clinic, ?array $branchIds = null): Collection
    {
        if ($branchIds !== null) {
            // Each patient's first completed visit, in the database: the
            // earliest day, a timed visit before an untimed one that day.
            $visits = $clinic->bookings()
                ->toBase()
                ->where('status', BookingStatus::DONE)
                ->select(['patient_id', 'branch_id', 'visit_date'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY patient_id ORDER BY visit_date, CASE WHEN start_at IS NULL THEN 1 ELSE 0 END, start_at, id) AS visit_number');

            return DB::query()
                ->fromSub($visits, 'visits')
                ->where('visit_number', 1)
                ->whereIn('branch_id', $branchIds)
                ->pluck('visit_date', 'patient_id')
                ->map(fn ($date) => Carbon::parse($date)->toDateString());
        }

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

    /** @param  list<int>|null  $branchIds */
    private function visitsBetween(Clinic $clinic, Carbon $from, Carbon $to, ?array $branchIds = null): int
    {
        return $clinic->bookings()
            ->where('status', BookingStatus::DONE)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
            ->count();
    }
}
