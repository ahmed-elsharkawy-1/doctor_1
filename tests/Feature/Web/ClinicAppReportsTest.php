<?php

namespace Tests\Feature\Web;

use App\Livewire\App\Reports;
use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ClinicAppReportsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 11 August 2026 — four days into a Saturday-start week, which
        // is the case a Monday-start assumption would get wrong.
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
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
    | The web reads what the API reads
    |--------------------------------------------------------------------------
    */

    public function test_revenue_matches_the_api_exactly(): void
    {
        $this->visit('2026-08-11', 300);
        $this->visit('2026-08-10', 200);
        $this->visit('2026-07-11', 150);

        Sanctum::actingAs($this->owner);

        $fromApi = $this->getJson(route('api.v1.reports.revenue'))->assertOk()->json('data');

        $fromWeb = Livewire::actingAs($this->owner)->test(Reports::class)->viewData('revenue');

        // Compared by value, not identity: json_encode drops the fraction from
        // a whole float, so 50.0 arrives from the API as 50. That is the wire
        // format, not a different number.
        $this->assertEquals($fromApi, $fromWeb);
    }

    public function test_retention_matches_the_api_for_every_offered_period(): void
    {
        $patient = Patient::factory()->for($this->clinic)->create();
        $this->visit('2026-07-01', 200, $patient);
        $this->visit('2026-08-05', 200, $patient);
        $this->visit('2026-08-10', 250);

        foreach (['this_week', 'this_month', 'last_90_days', 'last_365_days'] as $period) {
            Sanctum::actingAs($this->owner);

            $fromApi = $this->getJson(route('api.v1.reports.retention', ['period' => $period]))
                ->assertOk()
                ->json('data');

            $fromWeb = Livewire::actingAs($this->owner)
                ->test(Reports::class, ['period' => $period])
                ->viewData('retention');

            $this->assertEquals($fromApi, $fromWeb, "Period {$period} disagrees with the API.");
        }
    }

    public function test_the_week_starts_on_saturday(): void
    {
        // Saturday 8 August opens the week that contains Tuesday the 11th.
        $this->visit('2026-08-08', 500);
        // Friday the 7th belongs to the week before.
        $this->visit('2026-08-07', 900);

        $week = Livewire::actingAs($this->owner)
            ->test(Reports::class)
            ->viewData('revenue')['periods']['this_week'];

        $this->assertSame('500.00', $week['total']['value']);
        $this->assertSame(1, $week['completed_visits']);
    }

    public function test_the_daily_series_covers_the_month_with_no_gaps(): void
    {
        $this->visit('2026-08-03', 100);

        $daily = Livewire::actingAs($this->owner)->test(Reports::class)->viewData('revenue')['daily'];

        // 1 to 11 August inclusive, whether or not anyone was seen.
        $this->assertCount(11, $daily);
    }

    /*
    |--------------------------------------------------------------------------
    | Boundaries
    |--------------------------------------------------------------------------
    */

    public function test_another_clinics_money_never_appears(): void
    {
        $other = $this->otherClinic();

        Booking::factory()->forClinic($other)
            ->at(Carbon::parse('2026-08-11 09:00', 'Africa/Cairo'))
            ->done()
            ->create(['price' => 9999]);

        $revenue = Livewire::actingAs($this->owner)->test(Reports::class)->viewData('revenue');

        $this->assertSame('0.00', $revenue['periods']['today']['total']['value']);
    }

    /**
     * No role today lacks `prices.view` — UserRole::CLINIC holds every ability
     * — so the account is stood in for. The point of the test is that the gate
     * is actually consulted, so that the day a limited role exists, revenue
     * disappears from this screen instead of leaking.
     */
    public function test_money_is_hidden_without_prices_view(): void
    {
        $this->visit('2026-08-11', 300);

        $user = Mockery::mock($this->owner)->makePartial();
        $user->shouldReceive('hasAbility')->with('prices.view')->andReturnFalse();
        $user->shouldReceive('hasAbility')->andReturnTrue();

        $page = Livewire::actingAs($user)->test(Reports::class);

        $this->assertNull($page->viewData('revenue'));
        // Retention stays: how busy the clinic is, without the takings.
        $this->assertNotNull($page->viewData('retention'));
    }

    public function test_reports_need_the_ability(): void
    {
        $this->get(route('app.reports'))->assertRedirect(route('app.login'));
    }

    public function test_an_unknown_period_falls_back_rather_than_breaking(): void
    {
        $page = Livewire::actingAs($this->owner)
            ->test(Reports::class, ['period' => 'last_decade'])
            ->assertSet('failed', true)
            ->assertSet('period', config('clinic.retention.default_period'));

        $this->assertNotNull($page->viewData('retention'));
    }
}
