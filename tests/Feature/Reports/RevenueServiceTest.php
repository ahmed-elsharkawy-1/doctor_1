<?php

namespace Tests\Feature\Reports;

use App\Enums\CancelReason;
use App\Models\Booking;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\RevenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class RevenueServiceTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private RevenueService $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 11 August 2026, so "this week" (Saturday-start) is 4 days in.
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->revenue = app(RevenueService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function completedVisit(string $date, float $price): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
            ->done()
            ->create(['price' => $price]);
    }

    public function test_it_totals_completed_visits_for_today(): void
    {
        $this->completedVisit('2026-08-11', 300);
        $this->completedVisit('2026-08-11', 150);

        $data = $this->revenue->forPeriod($this->clinic, ReportPeriod::today($this->clinic));

        $this->assertSame(450.0, $data['total']);
        $this->assertSame(2, $data['count']);
    }

    /**
     * Only completed visits are worth anything.
     */
    public function test_cancellations_and_no_shows_are_worth_nothing(): void
    {
        $this->completedVisit('2026-08-11', 300);

        foreach ([CancelReason::NO_SHOW, CancelReason::PATIENT_CANCELLED, CancelReason::EMERGENCY, CancelReason::INCOMPLETE] as $reason) {
            Booking::factory()->forClinic($this->clinic)
                ->at(Carbon::parse('2026-08-11 10:00', 'Africa/Cairo'))
                ->cancelled($reason)
                ->create(['price' => 999]);
        }

        $this->assertSame(
            300.0,
            $this->revenue->forPeriod($this->clinic, ReportPeriod::today($this->clinic))['total'],
        );
    }

    public function test_bookings_not_yet_finished_are_not_counted(): void
    {
        $this->completedVisit('2026-08-11', 300);

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-11 10:00', 'Africa/Cairo'))
            ->arrived()
            ->create(['price' => 999]);

        $this->assertSame(
            300.0,
            $this->revenue->forPeriod($this->clinic, ReportPeriod::today($this->clinic))['total'],
        );
    }

    /**
     * The snapshot is what makes history stable — SPEC §3.3.
     */
    public function test_changing_a_visit_types_price_does_not_rewrite_past_revenue(): void
    {
        $booking = $this->completedVisit('2026-08-11', 300);

        $booking->visitType->update(['price' => 900]);

        $this->assertSame(
            300.0,
            $this->revenue->forPeriod($this->clinic, ReportPeriod::today($this->clinic))['total'],
        );
    }

    public function test_today_is_compared_against_yesterday(): void
    {
        $this->completedVisit('2026-08-11', 300);
        $this->completedVisit('2026-08-10', 200);

        $data = $this->revenue->forPeriod($this->clinic, ReportPeriod::today($this->clinic));

        $this->assertSame(300.0, $data['total']);
        $this->assertSame(200.0, $data['previous_total']);
        $this->assertSame(100.0, $data['difference']);
        $this->assertSame(50.0, $data['change_percent']);
        $this->assertSame('up', $data['direction']);
    }

    public function test_a_drop_is_reported_as_such(): void
    {
        $this->completedVisit('2026-08-11', 100);
        $this->completedVisit('2026-08-10', 400);

        $data = $this->revenue->forPeriod($this->clinic, ReportPeriod::today($this->clinic));

        $this->assertSame(-300.0, $data['difference']);
        $this->assertSame(-75.0, $data['change_percent']);
        $this->assertSame('down', $data['direction']);
    }

    public function test_growth_from_zero_reports_no_percentage(): void
    {
        $this->completedVisit('2026-08-11', 300);

        $data = $this->revenue->forPeriod($this->clinic, ReportPeriod::today($this->clinic));

        $this->assertNull($data['change_percent']);
        $this->assertSame('up', $data['direction']);
    }

    public function test_the_business_week_starts_on_saturday(): void
    {
        $period = ReportPeriod::thisWeek($this->clinic);

        $this->assertSame('2026-08-08', $period->from->toDateString());
        $this->assertSame('2026-08-11', $period->to->toDateString());
    }

    /**
     * The single most important rule in §5.5: a partial period is never
     * compared against a complete one.
     */
    public function test_this_week_is_compared_against_the_same_days_of_last_week(): void
    {
        $period = ReportPeriod::thisWeek($this->clinic);

        $this->assertSame('2026-08-01', $period->previousFrom->toDateString());
        // Tuesday, not the whole of last week.
        $this->assertSame('2026-08-04', $period->previousTo->toDateString());
    }

    public function test_this_week_totals_only_the_days_elapsed(): void
    {
        $this->completedVisit('2026-08-08', 100);   // Saturday, in
        $this->completedVisit('2026-08-11', 200);   // Tuesday, in
        $this->completedVisit('2026-08-07', 999);   // Friday, previous week

        // Last week, same span: Sat 1 - Tue 4.
        $this->completedVisit('2026-08-02', 50);
        $this->completedVisit('2026-08-06', 999);   // Thursday, past the span

        $data = $this->revenue->forPeriod($this->clinic, ReportPeriod::thisWeek($this->clinic));

        $this->assertSame(300.0, $data['total']);
        $this->assertSame(50.0, $data['previous_total']);
    }

    public function test_this_month_is_compared_against_the_same_days_of_last_month(): void
    {
        $period = ReportPeriod::thisMonth($this->clinic);

        $this->assertSame('2026-08-01', $period->from->toDateString());
        $this->assertSame('2026-08-11', $period->to->toDateString());
        $this->assertSame('2026-07-01', $period->previousFrom->toDateString());
        $this->assertSame('2026-07-11', $period->previousTo->toDateString());
    }

    /**
     * On the 31st, "the same day last month" does not exist in a 30-day month.
     */
    public function test_a_short_previous_month_is_clamped_to_its_last_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'Africa/Cairo'));

        $period = ReportPeriod::thisMonth($this->clinic);

        $this->assertSame('2026-07-01', $period->previousFrom->toDateString());
        $this->assertSame('2026-07-31', $period->previousTo->toDateString());
    }

    public function test_the_daily_series_has_no_gaps(): void
    {
        $this->completedVisit('2026-08-09', 100);

        $series = $this->revenue->daily(
            $this->clinic,
            Carbon::parse('2026-08-08'),
            Carbon::parse('2026-08-11'),
        );

        $this->assertSame(
            ['2026-08-08' => 0.0, '2026-08-09' => 100.0, '2026-08-10' => 0.0, '2026-08-11' => 0.0],
            $series,
        );
    }

    public function test_another_clinics_revenue_is_never_included(): void
    {
        $this->completedVisit('2026-08-11', 300);

        Booking::factory()->forClinic($this->otherClinic())
            ->at(Carbon::parse('2026-08-11 09:00', 'Africa/Cairo'))
            ->done()
            ->create(['price' => 5000]);

        $this->assertSame(
            300.0,
            $this->revenue->forPeriod($this->clinic, ReportPeriod::today($this->clinic))['total'],
        );
    }
}
