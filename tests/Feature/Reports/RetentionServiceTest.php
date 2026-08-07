<?php

namespace Tests\Feature\Reports;

use App\Models\Booking;
use App\Models\Patient;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class RetentionServiceTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private RetentionService $retention;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->retention = app(RetentionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $visitDates
     */
    private function patientWithVisits(array $visitDates): Patient
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);

        foreach ($visitDates as $date) {
            Booking::factory()
                ->forClinic($this->clinic)
                ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
                ->done()
                ->create(['patient_id' => $patient->id]);
        }

        return $patient;
    }

    private function thisMonth(): array
    {
        return $this->retention->forPeriod($this->clinic, ReportPeriod::thisMonth($this->clinic));
    }

    /**
     * The cohort is defined by the *first* visit, not by any visit in the
     * period — SPEC §5.6.
     */
    public function test_the_cohort_is_patients_whose_first_visit_falls_in_the_period(): void
    {
        $this->patientWithVisits(['2026-08-03']);            // in
        $this->patientWithVisits(['2026-08-09']);            // in
        $this->patientWithVisits(['2026-05-01', '2026-08-05']); // first visit was May, not in

        $this->assertSame(2, $this->thisMonth()['cohort_size']);
    }

    public function test_the_return_rate_counts_cohort_patients_who_came_back(): void
    {
        $this->patientWithVisits(['2026-08-01', '2026-08-09']);  // returned
        $this->patientWithVisits(['2026-08-02', '2026-08-10']);  // returned
        $this->patientWithVisits(['2026-08-03']);                // did not
        $this->patientWithVisits(['2026-08-04']);                // did not

        $data = $this->thisMonth();

        $this->assertSame(4, $data['cohort_size']);
        $this->assertSame(2, $data['returned_count']);
        $this->assertSame(50.0, $data['return_rate']);
    }

    public function test_an_empty_cohort_reports_no_rate_rather_than_zero(): void
    {
        $data = $this->thisMonth();

        $this->assertSame(0, $data['cohort_size']);
        $this->assertNull($data['return_rate']);
    }

    /**
     * The rule that makes the month-1 number meaningful: a patient seen last
     * week has not "never returned", she just has not returned yet.
     */
    public function test_a_recent_single_visit_is_not_counted_as_never_returned(): void
    {
        // 60-day maturity: 9 August is far too recent to judge.
        $this->patientWithVisits(['2026-08-09']);

        $data = $this->thisMonth();

        $this->assertSame(0, $data['first_visit_only_count']);
        $this->assertSame(1, $data['maturing_count']);
    }

    public function test_a_single_visit_older_than_the_window_counts_as_never_returned(): void
    {
        // 1 March is more than 60 days before 11 August.
        $this->patientWithVisits(['2026-03-01']);

        $data = $this->retention->forPeriod(
            $this->clinic,
            ReportPeriod::between(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')),
        );

        $this->assertSame(1, $data['cohort_size']);
        $this->assertSame(1, $data['first_visit_only_count']);
        $this->assertSame(0, $data['maturing_count']);
    }

    public function test_the_maturity_window_follows_the_clinic_setting(): void
    {
        $this->patientWithVisits(['2026-07-01']);

        $period = ReportPeriod::between(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        // 41 days ago: still maturing at 60 days.
        $this->assertSame(0, $this->retention->forPeriod($this->clinic, $period)['first_visit_only_count']);

        $this->clinic->update(['first_visit_only_days' => 30]);

        // Same patient, tighter window: now she counts as never returned.
        $this->assertSame(1, $this->retention->forPeriod($this->clinic->refresh(), $period)['first_visit_only_count']);
    }

    public function test_a_patient_who_returned_is_never_counted_as_never_returned(): void
    {
        $this->patientWithVisits(['2026-03-01', '2026-03-20']);

        $data = $this->retention->forPeriod(
            $this->clinic,
            ReportPeriod::between(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')),
        );

        $this->assertSame(1, $data['returned_count']);
        $this->assertSame(0, $data['first_visit_only_count']);
    }

    /**
     * Only completed visits count — a booking she never showed up for is not
     * a visit.
     */
    public function test_cancelled_bookings_do_not_make_a_patient_returning(): void
    {
        $patient = $this->patientWithVisits(['2026-08-01']);

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-09 09:00', 'Africa/Cairo'))
            ->cancelled()
            ->create(['patient_id' => $patient->id]);

        $data = $this->thisMonth();

        $this->assertSame(1, $data['cohort_size']);
        $this->assertSame(0, $data['returned_count']);
    }

    public function test_it_reports_visits_in_the_period_and_total_patients(): void
    {
        $this->patientWithVisits(['2026-08-01', '2026-08-09']);
        $this->patientWithVisits(['2026-08-03']);
        Patient::factory()->create(['clinic_id' => $this->clinic->id]);

        $data = $this->thisMonth();

        $this->assertSame(3, $data['visits_in_period']);
        $this->assertSame(3, $data['total_patients']);
    }

    public function test_another_clinics_patients_are_never_counted(): void
    {
        $this->patientWithVisits(['2026-08-01']);

        $other = $this->otherClinic();
        Booking::factory()->forClinic($other)
            ->at(Carbon::parse('2026-08-02 09:00', 'Africa/Cairo'))
            ->done()
            ->create();

        $data = $this->thisMonth();

        $this->assertSame(1, $data['cohort_size']);
        $this->assertSame(1, $data['total_patients']);
    }
}
