<?php

namespace Tests\Feature\Web;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The page handed to anyone who has to test the flow.
 *
 * Its whole value is being current, and it is the one page nothing else
 * exercises — it drifted once already, still naming a tracking path that had
 * moved and stopping halfway through a flow that had grown a second half.
 */
class HandoffPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['slug' => 'dr-sara']);

        config([
            'clinic.docs.enabled' => true,
            'clinic.docs.demo_account' => $this->owner->email,
            'clinic.docs.pilot_account' => $this->secretary->email,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_lists_every_surface_a_tester_needs(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertOk()
            ->assertSee(url(config('clinic.tracking.path')).'/{token}', escape: false)
            ->assertSee(url(config('clinic.review.path')).'/{token}', escape: false)
            ->assertSee(url(config('clinic.panel.path').'/reviews'), escape: false)
            ->assertSee(url('/dr-sara'), escape: false);
    }

    /**
     * The flow used to stop at "visit finished", which was the half a tester
     * could already see for themselves.
     */
    public function test_the_flow_runs_past_the_visit_to_the_review(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertOk()
            ->assertSee('إنهاء الكشف', escape: false)
            ->assertSee(config('clinic.review.path').'/{token}', escape: false);
    }

    public function test_the_pilot_clinic_is_listed_with_its_login(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertOk()
            ->assertSee('Pilot Clinic')
            ->assertSee($this->secretary->email);
    }

    /**
     * The demo clinic's bookings are listed by patient name. The pilot takes
     * real patients, so its bookings must never appear here.
     */
    public function test_it_never_lists_the_pilot_clinics_patients(): void
    {
        $booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-12 11:00', $this->clinic->timezone))
            ->create();

        $this->get(route('docs.api.handoff'))
            ->assertOk()
            // The demo clinic is the one whose queue is shown.
            ->assertSee($booking->patient->name);

        config(['clinic.docs.demo_account' => 'nobody@example.test']);

        $this->get(route('docs.api.handoff'))
            ->assertOk()
            ->assertDontSee($booking->patient->name);
    }

    /** A public page: the production admin login must never be on it. */
    public function test_it_never_publishes_the_admin_login(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertOk()
            ->assertDontSee(config('clinic.super_admin.email'))
            ->assertDontSee('admin@doctor1.test');
    }

    /** The pilot clinic takes real patients; its password is shared privately. */
    public function test_the_pilot_password_is_not_published(): void
    {
        $html = $this->get(route('docs.api.handoff'))->getContent();

        $pilot = substr($html, strpos($html, 'Pilot Clinic'), 2500);
        $pilot = substr($pilot, 0, strpos($pilot, '</article>'));

        $this->assertStringNotContainsString('<code>password</code>', $pilot);
        $this->assertStringContainsString('shared privately', $pilot);
    }

    /** It says what the pilot clinic's WhatsApp is doing now, not what it once did. */
    public function test_the_pilot_whatsapp_state_is_live(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        config(['clinic.docs.pilot_account' => $this->owner->email]);

        $this->get(route('docs.api.handoff'))->assertSee('WhatsApp switched off');

        $this->clinic->update(['whatsapp_enabled' => true]);

        $this->get(route('docs.api.handoff'))->assertSee('WhatsApp on');
    }

    public function test_it_points_the_team_at_staging_reports_and_the_api_changes(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertOk()
            ->assertSee('https://staging.elayadah.com/api/v1')
            ->assertSee(route('reports.index'))
            ->assertSee('whatsapp_enabled')
            ->assertSee('WHATSAPP_DISABLED')
            // The assistant has her own login now.
            ->assertSee('nour@doctor1.test');
    }

    public function test_it_stays_off_when_the_docs_are_disabled(): void
    {
        config(['clinic.docs.enabled' => false]);

        $this->get(route('docs.api.handoff'))->assertNotFound();
    }
}
