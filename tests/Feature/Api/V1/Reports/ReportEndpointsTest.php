<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ReportEndpointsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 11 August 2026 — four days into a Saturday-start week.
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function visit(string $date, float $price, ?Patient $patient = null): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
            ->done()
            ->create(array_filter([
                'price' => $price,
                'patient_id' => $patient?->id,
            ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Revenue
    |--------------------------------------------------------------------------
    */

    public function test_it_returns_all_three_periods_with_their_comparisons(): void
    {
        $this->visit('2026-08-11', 300);
        $this->visit('2026-08-10', 200);

        $data = $this->getJson(route('api.v1.reports.revenue'))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'currency',
                    'periods' => [
                        'today' => ['total', 'completed_visits', 'from', 'to', 'comparison'],
                        'this_week' => ['total', 'comparison'],
                        'this_month' => ['total', 'comparison'],
                    ],
                    'daily',
                ],
            ])
            ->json('data');

        $this->assertSame('300.00', $data['periods']['today']['total']['value']);
        $this->assertSame('200.00', $data['periods']['today']['comparison']['previous_total']['value']);
        $this->assertEquals(50, $data['periods']['today']['comparison']['change_percent']);
        $this->assertSame('up', $data['periods']['today']['comparison']['direction']);
    }

    public function test_money_carries_a_display_string(): void
    {
        $this->visit('2026-08-11', 300);

        $total = $this->getJson(route('api.v1.reports.revenue'))->json('data.periods.today.total');

        $this->assertSame('300.00', $total['value']);
        $this->assertStringContainsString('300', $total['display']);
    }

    public function test_growth_from_nothing_reports_a_null_percentage(): void
    {
        $this->visit('2026-08-11', 300);

        $this->getJson(route('api.v1.reports.revenue'))
            ->assertOk()
            ->assertJsonPath('data.periods.today.comparison.change_percent', null)
            ->assertJsonPath('data.periods.today.comparison.direction', 'up');
    }

    public function test_the_daily_series_covers_the_month_with_no_gaps(): void
    {
        $this->visit('2026-08-09', 100);

        $daily = $this->getJson(route('api.v1.reports.revenue'))->json('data.daily');

        // 1st through the 11th.
        $this->assertCount(11, $daily);
        $this->assertSame('2026-08-01', $daily[0]['date']['value']);
        $this->assertSame('0.00', $daily[0]['total']['value']);
        $this->assertSame('100.00', $daily[8]['total']['value']);
    }

    public function test_cancelled_visits_are_worth_nothing(): void
    {
        $this->visit('2026-08-11', 300);

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-11 10:00', 'Africa/Cairo'))
            ->cancelled()
            ->create(['price' => 999]);

        $this->getJson(route('api.v1.reports.revenue'))
            ->assertJsonPath('data.periods.today.total.value', '300.00');
    }

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */

    public function test_retention_defaults_to_this_month(): void
    {
        $this->getJson(route('api.v1.reports.retention'))
            ->assertOk()
            ->assertJsonPath('data.period.value', 'this_month')
            ->assertJsonStructure([
                'data' => [
                    'period' => ['value', 'display', 'from', 'to'],
                    'available_periods',
                    'cohort_size', 'returned_count', 'return_rate',
                    'first_visit_only_count', 'maturing_count', 'maturity_days',
                    'visits_in_period', 'total_patients',
                ],
            ]);
    }

    public function test_it_reports_the_cohort_and_who_came_back(): void
    {
        $returned = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
        $this->visit('2026-08-01', 300, $returned);
        $this->visit('2026-08-09', 200, $returned);

        $once = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
        $this->visit('2026-08-02', 300, $once);

        $this->getJson(route('api.v1.reports.retention'))
            ->assertOk()
            ->assertJsonPath('data.cohort_size', 2)
            ->assertJsonPath('data.returned_count', 1)
            ->assertJsonPath('data.return_rate', 50);
    }

    /**
     * A patient seen a few days ago has not "never returned" — she just has
     * not returned yet, and is reported separately.
     */
    public function test_a_recent_single_visit_is_counted_as_maturing(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
        $this->visit('2026-08-09', 300, $patient);

        $this->getJson(route('api.v1.reports.retention'))
            ->assertOk()
            ->assertJsonPath('data.first_visit_only_count', 0)
            ->assertJsonPath('data.maturing_count', 1)
            ->assertJsonPath('data.maturity_days', $this->clinic->first_visit_only_days);
    }

    public function test_an_empty_cohort_reports_a_null_rate(): void
    {
        $this->getJson(route('api.v1.reports.retention'))
            ->assertOk()
            ->assertJsonPath('data.cohort_size', 0)
            ->assertJsonPath('data.return_rate', null);
    }

    public function test_it_accepts_the_other_periods(): void
    {
        foreach (['this_week', 'this_month', 'last_90_days', 'last_365_days'] as $period) {
            $this->getJson(route('api.v1.reports.retention', ['period' => $period]))
                ->assertOk()
                ->assertJsonPath('data.period.value', $period);
        }
    }

    public function test_an_unknown_period_is_rejected_with_the_allowed_list(): void
    {
        $this->getJson(route('api.v1.reports.retention', ['period' => 'since_forever']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_REPORT_PERIOD')
            ->assertJsonPath('error.details.allowed.0', 'this_week');
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function test_the_clinic_account_can_see_reports(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->getJson(route('api.v1.reports.revenue'))
            ->assertOk();

        $this->getJson(route('api.v1.reports.retention'))
            ->assertOk();
    }

    public function test_reports_require_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(route('api.v1.reports.revenue'))->assertStatus(401);
    }

    public function test_another_clinics_figures_never_leak_in(): void
    {
        $this->visit('2026-08-11', 300);

        Booking::factory()->forClinic($this->otherClinic())
            ->at(Carbon::parse('2026-08-11 09:00', 'Africa/Cairo'))
            ->done()
            ->create(['price' => 9999]);

        $this->getJson(route('api.v1.reports.revenue'))
            ->assertJsonPath('data.periods.today.total.value', '300.00');
    }
}
