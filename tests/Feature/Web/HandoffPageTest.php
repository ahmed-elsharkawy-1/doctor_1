<?php

namespace Tests\Feature\Web;

use App\Support\TestClinic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The page a tester opens first: where things are, and how to sign in.
 *
 * Kept to what is in use — the mobile API and the admin panel — with the one
 * test clinic's logins, which are the same on every environment. It is
 * public, so no login that guards real data is ever printed on it.
 */
class HandoffPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['name' => 'عيادة د. سهام', 'slug' => 'dr-seham']);

        config([
            'clinic.docs.enabled' => true,
            'clinic.docs.pilot_account' => $this->owner->email,
        ]);
    }

    public function test_it_gives_the_test_clinic_logins_for_every_environment(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertOk()
            ->assertSee(TestClinic::NAME)
            ->assertSee(TestClinic::DOCTOR_EMAIL)
            ->assertSee(TestClinic::DOCTOR_PASSWORD)
            ->assertSee(TestClinic::ASSISTANT_EMAIL)
            ->assertSee(TestClinic::ASSISTANT_PASSWORD);
    }

    public function test_it_links_production_and_staging(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertSee('https://elayadah.com/api/v1')
            ->assertSee('https://staging.elayadah.com/api/v1')
            ->assertSee('https://elayadah.com/admin')
            ->assertSee('https://staging.elayadah.com/admin')
            ->assertSee('https://elayadah.com/reports')
            ->assertSee('https://elayadah.com/'.TestClinic::SLUG);
    }

    public function test_it_links_the_api_reference(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertSee(route('docs.api'), escape: false)
            ->assertSee(route('docs.api.spec'), escape: false);
    }

    /** A public page: the production admin login is never on it. */
    public function test_it_never_publishes_the_admin_login(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertDontSee(config('clinic.super_admin.email'))
            ->assertDontSee('admin@doctor1.test');
    }

    /** The pilot clinic is real: its address and email, never its password. */
    public function test_the_pilot_clinic_is_listed_without_its_password(): void
    {
        $this->owner->update(['password' => 'pilot-secret']);

        $this->get(route('docs.api.handoff'))
            ->assertSee('عيادة د. سهام')
            ->assertSee($this->owner->email)
            ->assertSee(url('dr-seham'))
            ->assertDontSee('pilot-secret')
            ->assertSee('shared privately');
    }

    public function test_the_pilot_whatsapp_state_is_live(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        $this->get(route('docs.api.handoff'))->assertSee('WhatsApp off');

        $this->clinic->update(['whatsapp_enabled' => true]);
        $this->get(route('docs.api.handoff'))->assertSee('WhatsApp on');
    }

    /** The /app screens are not in use; the page does not send anyone there. */
    public function test_it_leaves_out_the_unused_web_app(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertDontSee(route('app.login'), escape: false)
            ->assertDontSee('Reservation Flow');
    }

    public function test_it_stays_off_when_the_docs_are_disabled(): void
    {
        config(['clinic.docs.enabled' => false]);

        $this->get(route('docs.api.handoff'))->assertNotFound();
    }
}
