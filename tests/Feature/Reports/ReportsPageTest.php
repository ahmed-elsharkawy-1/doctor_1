<?php

namespace Tests\Feature\Reports;

use App\Enums\DayOfWeek;
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

        // Open every day, like a working clinic; tests that need a day off
        // close it themselves. Provisioning leaves the week closed.
        $this->clinic->schedules()->update(['is_open' => true]);
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

    public function test_the_page_offers_the_six_days_and_the_periods(): void
    {
        $html = $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)->assertOk()->getContent();

        $this->assertStringContainsString('/reports/day/2026-10-03', $html);
        $this->assertStringContainsString('/reports/day/2026-09-28', $html);
        $this->assertStringNotContainsString('/reports/day/2026-09-27', $html);
        $this->assertStringNotContainsString('/reports/day/2026-10-04', $html);
        $this->assertStringContainsString('/reports/week/2026-09-26', $html);
        $this->assertStringContainsString('/reports/month/2026-05', $html);
        $this->assertStringNotContainsString('/reports/month/2026-04', $html);
    }

    /**
     * One dropdown holds every period, grouped, with exactly the one on
     * screen selected — so the control always names what the numbers are for.
     */
    public function test_one_grouped_dropdown_selects_the_period_on_screen(): void
    {
        foreach (['/reports/day/2026-09-28', '/reports/week/2026-09-26', '/reports/month/2026-05'] as $page) {
            $html = $this->actingAs($this->owner)->get($page)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<select'), $page);
            $this->assertSame(3, substr_count($html, '<optgroup'), $page);
            $this->assertSame(1, preg_match_all('#<option\s[^>]*selected#', $html), $page);
            $this->assertMatchesRegularExpression('#<option\s+value="[^"]*'.preg_quote($page, '#').'"\s+selected#', $html, $page);
        }
    }

    /** Yesterday is named as yesterday — it is what the morning message is about. */
    public function test_yesterday_is_named_in_the_dropdown(): void
    {
        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertSee(__('reports.page.yesterday'))
            ->assertSee(__('reports.page.group_days'))
            ->assertSee(__('reports.page.group_weeks'))
            ->assertSee(__('reports.page.group_months'));
    }

    /*
    |--------------------------------------------------------------------------
    | Only what has something to say
    |--------------------------------------------------------------------------
    */

    /** Nothing booked at all: one sentence, not a page of zeros. */
    public function test_an_empty_day_says_so_once(): void
    {
        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertOk()
            ->assertSee(__('reports.page.no_bookings'))
            ->assertDontSee(__('reports.page.completed'))
            ->assertDontSee(__('reports.page.by_type'))
            ->assertDontSee(__('reports.page.outcomes'))
            ->assertDontSee(__('reports.page.patients'))
            // Still worth knowing on an empty day.
            ->assertSee(__('reports.page.next_day'));
    }

    /** Bookings but nobody seen: the numbers and why — no empty tables. */
    public function test_no_completed_visits_shows_the_outcomes_but_no_empty_sections(): void
    {
        foreach (['10:00', '11:00'] as $time) {
            Booking::factory()->forClinic($this->clinic)->at(Carbon::parse(self::YESTERDAY.' '.$time, 'Africa/Cairo'))->noShow()->create();
        }

        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertSee(__('reports.page.completed'))
            ->assertSee(__('reports.page.outcomes'))
            ->assertDontSee(__('reports.page.by_type'))
            ->assertDontSee(__('reports.page.patients'))
            ->assertDontSee(__('reports.page.no_bookings'));
    }

    /** The comparison says what it is compared with, and only when there was something. */
    public function test_the_comparison_names_its_period_and_hides_when_empty(): void
    {
        $this->visitOn(self::YESTERDAY, 500);

        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertDontSee('class="rp-compare"', escape: false);

        $this->visitOn('2026-09-26', 400); // the same Saturday a week before

        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertSee('class="rp-compare"', escape: false)
            ->assertSee('↑ 25')
            ->assertSee('26');
    }

    public function test_a_day_off_says_the_clinic_was_closed(): void
    {
        $this->clinic->scheduleFor(DayOfWeek::SATURDAY)->update(['is_open' => false]);

        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertSee(__('reports.page.was_closed'))
            ->assertDontSee(__('reports.page.no_bookings'));
    }

    public function test_a_holiday_says_so_with_its_note(): void
    {
        $this->clinic->holidays()->create(['date' => self::YESTERDAY, 'note' => 'إجازة أكتوبر']);

        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertSee(__('reports.page.was_holiday'))
            ->assertSee('إجازة أكتوبر');
    }

    /** A week lists all seven days, each saying what it was. */
    public function test_a_week_labels_closed_days_and_holidays(): void
    {
        $this->visitOn('2026-09-26', 400);
        $this->clinic->holidays()->create(['date' => '2026-09-27', 'note' => null]);
        $this->clinic->scheduleFor(DayOfWeek::FRIDAY)->update(['is_open' => false]);

        $this->actingAs($this->owner)->get('/reports/week/2026-09-26')
            ->assertSee(__('reports.page.by_day'))
            ->assertSee(__('reports.page.row_holiday'))
            ->assertSee(__('reports.page.row_closed'))
            ->assertSee(__('reports.page.row_none'));
    }

    /** A month reads week by week — four or five rows, never thirty. */
    public function test_a_month_shows_weeks_not_days(): void
    {
        $this->visitOn('2026-09-10', 400);

        $html = $this->actingAs($this->owner)->get('/reports/month/2026-09')
            ->assertSee(__('reports.page.by_week'))
            ->assertDontSee(__('reports.page.by_day'))
            ->getContent();

        $this->assertSame(5, substr_count($html, 'class="rp-weekrow'));
    }

    /** A long new-patients list shows five, and the rest one tap away in place. */
    public function test_a_long_new_patients_list_shows_five_then_the_rest_on_request(): void
    {
        foreach (range(1, 7) as $i) {
            $patient = Patient::factory()->for($this->clinic)->create(['name' => "مريضة رقم {$i}"]);
            Booking::factory()->forClinic($this->clinic)->at(Carbon::parse(self::YESTERDAY.' 10:00', 'Africa/Cairo')->addMinutes($i * 10))->done()
                ->create(['patient_id' => $patient->id]);
        }

        $html = $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)->getContent();

        $visible = substr($html, 0, strpos($html, '<details'));
        $this->assertSame(5, substr_count($visible, 'class="rp-patientrow'));
        $this->assertSame(7, substr_count($html, 'class="rp-patientrow'));
        $this->assertStringContainsString(e(__('reports.page.show_all', ['count' => 7])), $html);
    }

    public function test_a_short_new_patients_list_has_nothing_to_expand(): void
    {
        $this->visitOn(self::YESTERDAY, 400);

        $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)
            ->assertDontSee('<details', escape: false);
    }

    /** The doctor's order: how it went, what is next, what went wrong, the rest. */
    public function test_the_sections_come_in_the_doctors_order(): void
    {
        $this->visitOn(self::YESTERDAY, 400);

        $html = $this->actingAs($this->owner)->get('/reports/day/'.self::YESTERDAY)->getContent();

        $order = array_map(fn ($key) => strpos($html, __('reports.page.'.$key)), ['completed', 'next_day', 'outcomes', 'by_type', 'patients']);
        $sorted = $order;
        sort($sorted);

        $this->assertNotContains(false, $order);
        $this->assertSame($sorted, $order);
    }

    private function visitOn(string $date, float $price): void
    {
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse($date.' 10:00', 'Africa/Cairo'))->done()->create(['price' => $price]);
    }

    public function test_a_week_shows_a_line_per_day(): void
    {
        $this->visitOn('2026-09-28', 300);

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
