<?php

namespace Tests\Feature\Reports;

use App\Enums\ApiErrorCode;
use App\Models\ReportWebLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The reports page inside the mobile app's WebView.
 *
 * The app asks with its own API token for a short, single-use link; opening
 * the link signs the WebView in. When that session lapses the page never
 * shows a password form — it sends the WebView to a fixed renew address the
 * app watches for, and the app quietly asks for a new link.
 */
class ReportAppLinkTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo', 'reports_enabled' => true]);
        $this->owner->update(['can_access_reports' => true]);
        $this->clinic->schedules()->update(['is_open' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** What the app does: ask for a link with its API token. */
    private function issue(): string
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson(route('api.v1.reports.web-link'))->assertOk()->json('data.url');
    }

    /** What the WebView does with it: no API token, its own cookies. */
    private function open(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get($url);
    }

    /*
    |--------------------------------------------------------------------------
    | The endpoint
    |--------------------------------------------------------------------------
    */

    public function test_the_app_gets_a_short_lived_link_with_its_token(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson(route('api.v1.reports.web-link'))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.renew_url', route('reports.app.renew'));

        $this->assertMatchesRegularExpression('#^'.preg_quote(url('/reports/app/open').'/', '#').'[A-Za-z0-9]{64}$#', $response->json('data.url'));
        $this->assertSame(
            now()->addSeconds(config('clinic.reports.app_link_ttl_seconds'))->toIso8601String(),
            Carbon::parse($response->json('data.expires_at'))->toIso8601String(),
        );
    }

    /** The same rule as the website: the account's flag and the clinic's switch. */
    public function test_an_account_without_report_access_is_refused(): void
    {
        $this->owner->update(['can_access_reports' => false]);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.reports.web-link'))
            ->assertForbidden()
            ->assertJsonPath('error.code', ApiErrorCode::REPORTS_NOT_AVAILABLE->value);

        $this->assertSame(0, ReportWebLink::count());
    }

    public function test_a_clinic_with_reports_off_is_refused(): void
    {
        $this->clinic->update(['reports_enabled' => false]);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.reports.web-link'))
            ->assertForbidden()
            ->assertJsonPath('error.code', ApiErrorCode::REPORTS_NOT_AVAILABLE->value);
    }

    /** Whoever the account is — owner or secretary — the report flag decides. */
    public function test_a_secretary_flagged_for_reports_may_open_them(): void
    {
        $this->secretary->update(['can_access_reports' => true]);
        Sanctum::actingAs($this->secretary);

        $this->postJson(route('api.v1.reports.web-link'))->assertOk();
    }

    public function test_it_needs_the_app_token(): void
    {
        $this->postJson(route('api.v1.reports.web-link'))->assertUnauthorized();
    }

    /** A link in a log or a screenshot gives nothing away: only its hash is kept. */
    public function test_the_link_itself_is_never_stored(): void
    {
        $url = $this->issue();
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertDatabaseMissing('report_web_links', ['token_hash' => $token]);
        $this->assertDatabaseHas('report_web_links', ['token_hash' => hash('sha256', $token), 'user_id' => $this->owner->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Opening it
    |--------------------------------------------------------------------------
    */

    public function test_opening_the_link_signs_the_webview_in_and_shows_the_report(): void
    {
        $this->open($this->issue())
            ->assertRedirect(route('reports.index'))
            // The marker a lapsed session is recognised by later.
            ->assertCookie(config('clinic.reports.app_cookie'), '1');

        $this->assertAuthenticatedAs($this->owner, 'web');
        $this->followingRedirects()->get(route('reports.index'))->assertOk()->assertSee($this->clinic->name);
    }

    /** Not the website's 90-day "remember me": the session ends on its own. */
    public function test_the_webview_session_is_not_remembered(): void
    {
        $response = $this->open($this->issue());

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringStartsNotWith('remember_web', $cookie->getName());
        }
    }

    public function test_a_link_opens_once(): void
    {
        $url = $this->issue();

        $this->open($url)->assertRedirect(route('reports.index'));
        $this->post(route('reports.logout'));

        $this->open($url)->assertRedirect(route('reports.app.renew'));
        $this->assertGuest('web');
    }

    public function test_a_link_lapses_after_two_minutes(): void
    {
        $url = $this->issue();

        Carbon::setTestNow(now()->addSeconds(config('clinic.reports.app_link_ttl_seconds') + 1));

        $this->open($url)->assertRedirect(route('reports.app.renew'));
        $this->assertGuest('web');
    }

    public function test_a_made_up_link_goes_to_renew(): void
    {
        $this->open(route('reports.app.open', ['token' => str_repeat('a', 64)]))
            ->assertRedirect(route('reports.app.renew'));

        $this->assertGuest('web');
    }

    /** Access taken away between asking and opening: nothing opens. */
    public function test_access_removed_before_opening_signs_nothing_in(): void
    {
        $url = $this->issue();
        $this->owner->update(['can_access_reports' => false]);

        $this->open($url)->assertRedirect(route('reports.app.renew'));
        $this->assertGuest('web');
    }

    /*
    |--------------------------------------------------------------------------
    | App mode
    |--------------------------------------------------------------------------
    */

    /** The app has its own frame: no brand bar, no sign-out, and plain links for the periods. */
    public function test_inside_the_app_the_page_drops_the_websites_chrome(): void
    {
        $this->open($this->issue());

        $html = $this->followingRedirects()->get(route('reports.index'))->getContent();

        $this->assertStringNotContainsString(route('reports.logout'), $html);
        $this->assertStringNotContainsString('class="brand-header"', $html);
        // Tapping a period is a plain link, not a script on a <select>.
        $this->assertStringNotContainsString('<select id="rp-period"', $html);
        $this->assertStringContainsString('href="'.route('reports.show', ['type' => 'day', 'value' => '2026-10-02']).'"', $html);
    }

    /** App mode follows the session, so tapping a period keeps it. */
    public function test_app_mode_survives_moving_between_periods(): void
    {
        $this->open($this->issue());

        $this->get(route('reports.show', ['type' => 'month', 'value' => '2026-09']))
            ->assertOk()
            ->assertDontSee(route('reports.logout'), escape: false);
    }

    /** The website itself is unchanged. */
    public function test_on_the_website_the_page_keeps_its_picker_and_sign_out(): void
    {
        $this->actingAs($this->owner, 'web');

        $this->followingRedirects()->get(route('reports.index'))
            ->assertSee('<select id="rp-period"', escape: false)
            ->assertSee(route('reports.logout'), escape: false);
    }

    /*
    |--------------------------------------------------------------------------
    | When the session lapses
    |--------------------------------------------------------------------------
    */

    /** The app's WebView never meets the password form: it goes to renew, which the app watches for. */
    public function test_a_lapsed_session_in_the_app_goes_to_renew_not_to_sign_in(): void
    {
        $this->open($this->issue());

        // The session ends; the app's own marker stays in the WebView. (The
        // test client does not carry cookies between requests as a WebView
        // does, so the marker is handed over explicitly.)
        $this->flushSession();
        $this->app['auth']->guard('web')->logout();

        $this->withCookie(config('clinic.reports.app_cookie'), '1')->get(route('reports.show', ['type' => 'day', 'value' => '2026-10-02']))
            ->assertRedirect(route('reports.app.renew'));
    }

    /** A browser that never came from the app still gets the sign-in page. */
    public function test_a_browser_without_the_app_marker_still_signs_in(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('reports.login'));
    }

    /**
     * A browser that once opened an app link (a developer's laptop, say) is
     * a website again as soon as someone signs in with a password there.
     */
    public function test_a_password_sign_in_leaves_app_mode(): void
    {
        $this->owner->update(['password' => 'secret-pass']);
        // Still in the app's session when the password form is used.
        $this->open($this->issue());

        $response = $this->withCookie(config('clinic.reports.app_cookie'), '1')
            ->post(route('reports.login.store'), ['email' => $this->owner->email, 'password' => 'secret-pass']);

        // The marker is cleared...
        $cleared = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('clinic.reports.app_cookie'));
        $this->assertNotNull($cleared, 'the app marker must be cleared on a password sign-in');
        $this->assertLessThan(time(), $cleared->getExpiresTime());

        // ...and the page is the website's again: sign-out and the picker are back.
        $this->followingRedirects()->get(route('reports.index'))
            ->assertSee(route('reports.logout'), escape: false)
            ->assertSee('<select id="rp-period"', escape: false);
    }

    /** Sign-out exists only on the website: after it, the next visit signs in. */
    public function test_signing_out_on_the_website_clears_the_app_marker(): void
    {
        $this->actingAs($this->owner, 'web');

        $response = $this->withCookie(config('clinic.reports.app_cookie'), '1')->post(route('reports.logout'));

        $cleared = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('clinic.reports.app_cookie'));
        $this->assertNotNull($cleared);
        $this->assertLessThan(time(), $cleared->getExpiresTime());
    }

    /** The fallback, if the app ever misses the address: a plain message, no password. */
    public function test_the_renew_page_asks_to_reopen_never_for_a_password(): void
    {
        $this->get(route('reports.app.renew'))
            ->assertOk()
            ->assertSee(__('reports.app.renew_title'))
            ->assertDontSee('type="password"', escape: false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
