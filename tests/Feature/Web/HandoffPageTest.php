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
            'clinic.docs.handoff_code' => self::CODE,
        ]);

        $this->unlock();
    }

    private const CODE = 'team-code';

    /** A browser that has not entered the code. */
    private function locked(): static
    {
        $this->defaultCookies = [];

        return $this;
    }

    /** What a browser that entered the right code carries. */
    private function unlock(string $code = self::CODE): void
    {
        $this->withCookie('handoff_access', hash('sha256', $code));
    }

    /*
    |--------------------------------------------------------------------------
    | The access code
    |--------------------------------------------------------------------------
    */

    /** Locked, the page sends only the form — none of the logins, not even hidden. */
    public function test_without_the_code_only_the_form_is_sent(): void
    {
        $this->locked()
            ->get(route('handoff'))
            ->assertOk()
            ->assertSee('Access code')
            ->assertDontSee(TestClinic::DOCTOR_EMAIL)
            ->assertDontSee(TestClinic::DOCTOR_PASSWORD);
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->locked()
            ->post(route('handoff.unlock'), ['code' => 'nope'])
            ->assertRedirect(route('handoff'))
            ->assertSessionHasErrors('code')
            ->assertCookieMissing('handoff_access');
    }

    public function test_the_right_code_opens_the_page_and_is_remembered(): void
    {
        $response = $this->locked()
            ->post(route('handoff.unlock'), ['code' => self::CODE])
            ->assertRedirect(route('handoff'))
            ->assertCookie('handoff_access');

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'handoff_access');
        $days = (int) round(($cookie->getExpiresTime() - time()) / 86400);
        $this->assertEqualsWithDelta(30, $days, 1);
    }

    /** Changing the code locks everyone out until they enter the new one. */
    public function test_a_changed_code_locks_old_browsers_out(): void
    {
        config(['clinic.docs.handoff_code' => 'new-code']);

        $this->get(route('handoff'))->assertDontSee(TestClinic::DOCTOR_EMAIL);
    }

    public function test_guessing_is_throttled(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->post(route('handoff.unlock'), ['code' => 'nope']);
        }

        $this->post(route('handoff.unlock'), ['code' => 'nope'])->assertStatus(429);
    }

    /** No code set on a server means closed, not open. */
    public function test_with_no_code_set_a_server_keeps_it_closed(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['clinic.docs.handoff_code' => null]);

        $this->get(route('handoff'))->assertDontSee(TestClinic::DOCTOR_EMAIL);

        // And with no code to match, nothing unlocks it — not even an empty one.
        // (Back to the test environment: production also enforces CSRF.)
        $this->app->detectEnvironment(fn (): string => 'testing');
        $this->locked()->post(route('handoff.unlock'), ['code' => ''])->assertSessionHasErrors('code');
    }

    /** Links already shared keep working. */
    public function test_the_old_address_redirects(): void
    {
        $this->get('/docs/api/handoff')->assertRedirect('/handoff');
    }

    public function test_the_short_address_cannot_be_taken_by_a_clinic(): void
    {
        $this->assertContains('handoff', config('clinic.landing.reserved'));
    }

    public function test_it_gives_the_test_clinic_logins_for_every_environment(): void
    {
        $this->get(route('handoff'))
            ->assertOk()
            ->assertSee(TestClinic::NAME)
            ->assertSee(TestClinic::DOCTOR_EMAIL)
            ->assertSee(TestClinic::DOCTOR_PASSWORD)
            ->assertSee(TestClinic::ASSISTANT_EMAIL)
            ->assertSee(TestClinic::ASSISTANT_PASSWORD);
    }

    public function test_it_links_production_and_staging(): void
    {
        $this->get(route('handoff'))
            ->assertSee('https://elayadah.com/api/v1')
            ->assertSee('https://staging.elayadah.com/api/v1')
            ->assertSee('https://elayadah.com/admin')
            ->assertSee('https://staging.elayadah.com/admin')
            ->assertSee('https://elayadah.com/reports')
            ->assertSee('https://elayadah.com/'.TestClinic::SLUG);
    }

    public function test_it_links_the_api_reference(): void
    {
        $this->get(route('handoff'))
            ->assertSee(route('docs.api'), escape: false)
            ->assertSee(route('docs.api.spec'), escape: false);
    }

    /** A public page: the production admin login is never on it. */
    public function test_it_never_publishes_the_admin_login(): void
    {
        $this->get(route('handoff'))
            ->assertDontSee(config('clinic.super_admin.email'))
            ->assertDontSee('admin@doctor1.test');
    }

    /** The pilot clinic is real: its address and email, never its password. */
    public function test_the_pilot_clinic_is_listed_without_its_password(): void
    {
        $this->owner->update(['password' => 'pilot-secret']);

        $this->get(route('handoff'))
            ->assertSee('عيادة د. سهام')
            ->assertSee($this->owner->email)
            ->assertSee(url('dr-seham'))
            ->assertDontSee('pilot-secret')
            ->assertSee('shared privately');
    }

    public function test_the_pilot_whatsapp_state_is_live(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        $this->get(route('handoff'))->assertSee('WhatsApp off');

        $this->clinic->update(['whatsapp_enabled' => true]);
        $this->get(route('handoff'))->assertSee('WhatsApp on');
    }

    /** The /app screens are not in use; the page does not send anyone there. */
    public function test_it_leaves_out_the_unused_web_app(): void
    {
        $this->get(route('handoff'))
            ->assertDontSee(route('app.login'), escape: false)
            ->assertDontSee('Reservation Flow');
    }

    public function test_it_stays_off_when_the_docs_are_disabled(): void
    {
        config(['clinic.docs.enabled' => false]);

        $this->get(route('handoff'))->assertNotFound();
    }
}
