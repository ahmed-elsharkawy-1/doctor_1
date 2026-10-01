<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The password in front of staging.
 *
 * Staging is a full copy of the app on a public address. Without this, a
 * patient who found it through a search engine could book a visit at a clinic
 * that will never see it. Production never sets the password, and with none
 * set the gate must not exist at all.
 */
class StagingGateTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'team';

    private const PASSWORD = 'correct-horse';

    public function test_with_no_password_configured_nothing_changes(): void
    {
        config(['clinic.staging_gate.password' => null]);

        $this->get('/')
            ->assertOk()
            ->assertHeaderMissing('WWW-Authenticate')
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_a_visitor_without_the_password_is_asked_for_it(): void
    {
        $this->gateOn();

        $this->get('/')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="staging", charset="UTF-8"');
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->gateOn();

        $this->withBasicAuth(self::USER, 'wrong')->get('/')->assertUnauthorized();
        $this->withBasicAuth('someone', self::PASSWORD)->get('/')->assertUnauthorized();
    }

    public function test_the_right_password_lets_the_team_in(): void
    {
        $this->gateOn();

        $this->withBasicAuth(self::USER, self::PASSWORD)->get('/')->assertOk();
    }

    /** Even a page somebody was let into must stay out of search results. */
    public function test_every_response_asks_not_to_be_indexed(): void
    {
        $this->gateOn();

        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->withBasicAuth(self::USER, self::PASSWORD)->get('/')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson('/api/v1/auth/me')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The mobile app cannot answer a browser password prompt, so a staging
     * build of it talks to the API directly. The API has its own tokens.
     */
    public function test_the_api_is_left_to_its_own_authentication(): void
    {
        $this->gateOn();

        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertHeaderMissing('WWW-Authenticate')
            ->assertJsonStructure(['error' => ['code']]);
    }

    /** The container health check has no password; behind the gate every deploy would fail. */
    public function test_the_health_check_is_left_open(): void
    {
        $this->gateOn();

        $this->get('/up')->assertOk();
    }

    /** Meta signs its own requests and cannot send a password. */
    public function test_the_whatsapp_webhook_is_left_open(): void
    {
        $this->gateOn();

        $this->get('/webhooks/whatsapp')->assertHeaderMissing('WWW-Authenticate');
    }

    private function gateOn(): void
    {
        config([
            'clinic.staging_gate.user' => self::USER,
            'clinic.staging_gate.password' => self::PASSWORD,
        ]);
    }
}
