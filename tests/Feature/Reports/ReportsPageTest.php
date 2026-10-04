<?php

namespace Tests\Feature\Reports;

use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The doctor's reports page: who gets in, and what they see.
 *
 * It shows a clinic's income and its patients' names and phones, so the link
 * in the WhatsApp message must be worth nothing on its own: the reader signs
 * in with their own account, and only accounts flagged for reports can.
 */
class ReportsPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const YESTERDAY = '2026-10-03';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo', 'name' => 'عيادة الاختبار', 'reports_enabled' => true]);

        $this->owner->update(['can_access_reports' => true, 'password' => 'secret-pass']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Getting in
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_is_sent_to_sign_in_and_then_to_the_day_the_link_named(): void
    {
        $this->get('/reports/day/2026-09-30')->assertRedirect(route('reports.login'));

        $this->post(route('reports.login.store'), ['email' => $this->owner->email, 'password' => 'secret-pass'])
            ->assertRedirect('/reports/day/2026-09-30');

        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_the_reports_home_opens_yesterday(): void
    {
        $this->actingAs($this->owner)->get('/reports')->assertRedirect('/reports/day/'.self::YESTERDAY);
    }

    /** Same answer as a wrong password: the page says nothing about who exists. */
    public function test_an_account_not_flagged_for_reports_cannot_sign_in(): void
    {
        $this->secretary->update(['password' => 'secret-pass']);

        $this->post(route('reports.login.store'), ['email' => $this->secretary->email, 'password' => 'secret-pass'])
            ->assertSessionHasErrors(['email' => __('auth.invalid_credentials')]);

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->post(route('reports.login.store'), ['email' => $this->owner->email, 'password' => 'nope'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_clinic_with_reports_switched_off_has_no_reports(): void
    {
        $this->clinic->update(['reports_enabled' => false]);

        $this->post(route('reports.login.store'), ['email' => $this->owner->email, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $this->owner->update(['is_active' => false]);

        $this->post(route('reports.login.store'), ['email' => $this->owner->email, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** A staff session from elsewhere is not a reports session. */
    public function test_a_signed_in_account_without_the_flag_is_turned_away(): void
    {
        $this->actingAs($this->secretary)
            ->get('/reports/day/'.self::YESTERDAY)
            ->assertRedirect(route('reports.login'));
    }

    public function test_the_doctor_stays_signed_in_for_about_ninety_days(): void
    {
        $response = $this->post(route('reports.login.store'), ['email' => $this->owner->email, 'password' => 'secret-pass']);

        $remember = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

        $this->assertNotNull($remember);
        $days = Carbon::createFromTimestamp($remember->getExpiresTime())->diffInDays(now());
        $this->assertEqualsWithDelta(90, abs($days), 1);
    }

    public function test_guessing_passwords_is_throttled(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->post(route('reports.login.store'), ['email' => $this->owner->email, 'password' => 'nope']);
        }

        $this->post(route('reports.login.store'), ['email' => $this->owner->email, 'password' => 'nope'])
            ->assertStatus(429);
    }

    public function test_signing_out(): void
    {
        $this->actingAs($this->owner)->post(route('reports.logout'))->assertRedirect(route('reports.login'));

        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | What is shown
    |--------------------------------------------------------------------------
    */

    public function test_the_day_shows_its_numbers_and_its_new_patients(): void
    {
        $patient = Patient::factory()->for($this->clinic)->create(['name' => 'سارة أحمد', 'phone' => '+201012225521']);
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse(self::YESTERDAY.' 10:00', 'Africa/Cairo'))->done()
            ->create(['patient_id' => $patient->id, 'price' => 940]);

        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertOk()
            ->assertSee('عيادة الاختبار')
            ->assertSee(__('reports.page.completed'))
            ->assertSee('940')
            ->assertSee('سارة أحمد')
            ->assertSee('01012225521')
            ->assertSee(__('reports.page.next_day'));
    }

    public function test_the_page_offers_the_seven_days_and_the_periods(): void
    {
        $html = $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)->assertOk()->getContent();

        $this->assertStringContainsString('/reports/day/2026-10-03', $html);
        $this->assertStringContainsString('/reports/day/2026-09-27', $html);
        $this->assertStringNotContainsString('/reports/day/2026-10-04', $html);
        $this->assertStringContainsString('/reports/week/2026-09-26', $html);
        $this->assertStringContainsString('/reports/month/2026-04', $html);
    }

    public function test_a_week_shows_a_line_per_day(): void
    {
        $this->actingAs($this->owner)->get('/reports/week/2026-09-26')
            ->assertOk()
            ->assertSee(__('reports.page.by_day'));
    }

    /** Only what the calendar allows; everything else does not exist. */
    public function test_a_period_outside_the_range_is_not_found(): void
    {
        $this->actingAs($this->owner)->get('/reports/day/2026-10-04')->assertNotFound();
        $this->actingAs($this->owner)->get('/reports/day/2026-01-01')->assertNotFound();
        $this->actingAs($this->owner)->get('/reports/month/2025-01')->assertNotFound();
    }

    /** The clinic comes from the account, never from the address. */
    public function test_another_clinics_numbers_never_appear(): void
    {
        $other = $this->otherClinic();
        $other->update(['name' => 'عيادة أخرى']);
        Booking::factory()->forClinic($other)->at(Carbon::parse(self::YESTERDAY.' 10:00', 'Africa/Cairo'))->done()
            ->create(['price' => 777]);

        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY.'?clinic='.$other->id)
            ->assertOk()
            ->assertDontSee('عيادة أخرى')
            ->assertDontSee('777');
    }

    public function test_the_page_is_never_cached_indexed_or_referred(): void
    {
        $response = $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
