<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Admin\Widgets\SmsBalanceWidget;
use App\Models\User;
use App\Services\Messaging\SmsBalance;
use App\Services\Messaging\ZadxBalanceChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ZADX SMS balance in the admin panel: a dashboard widget, and a banner
 * on every page once it needs attention.
 */
class SmsBalanceAlertTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://smsapi.zadx.test/api/v1/sms/balance';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', 'Africa/Cairo'));

        config([
            'services.zadx.base_url' => 'https://smsapi.zadx.test/api/v1',
            'services.zadx.api_key' => 'pk_test',
            'services.zadx.api_secret' => 'sk_test',
            'services.zadx.balance.low_credits' => 100,
            'services.zadx.balance.expiry_days' => 7,
            'services.zadx.balance.cache_minutes' => 10,
        ]);

        $this->admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function balance(array $overrides = []): void
    {
        Http::fake([self::URL => Http::response($overrides + [
            'has_active_plan' => true,
            'plan' => '1,000 SMS',
            'remaining_credits' => 254,
            'quota' => 1000,
            'expires_at' => '2026-12-02T11:31:35+00:00',
        ])]);
    }

    private function adminPage()
    {
        return $this->actingAs($this->admin)->get('/'.config('clinic.panel.path').'/clinics');
    }

    public function test_it_reads_the_balance_with_the_apps_credentials(): void
    {
        $this->balance();

        $balance = app(ZadxBalanceChecker::class)->current();

        $this->assertSame(254, $balance->remaining);
        $this->assertSame(1000, $balance->quota);
        $this->assertSame('1,000 SMS', $balance->plan);
        $this->assertSame('2026-12-02', $balance->expiresOn());
        $this->assertNull($balance->alert());

        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request->method() === 'GET'
            && $request->header('X-Api-Key') === ['pk_test']
            && $request->header('X-Api-Secret') === ['sk_test']);
    }

    public function test_the_reading_is_cached_between_pages(): void
    {
        $this->balance();

        app(ZadxBalanceChecker::class)->current();
        app(ZadxBalanceChecker::class)->current();

        Http::assertSentCount(1);
    }

    public function test_the_dashboard_shows_the_balance(): void
    {
        $this->balance();

        Livewire::actingAs($this->admin)
            ->test(SmsBalanceWidget::class)
            ->assertOk()
            ->assertSee('254')
            ->assertSee('1,000 SMS')
            ->assertSee('2026-12-02')
            ->assertSee(__('filament.sms_balance.days_left', ['days' => 28]));
    }

    public function test_a_healthy_balance_shows_no_banner(): void
    {
        $this->balance();

        $this->adminPage()->assertOk()->assertDontSee('data-sms-balance-alert', escape: false);
    }

    public function test_a_low_balance_shows_a_warning_on_every_page(): void
    {
        $this->balance(['remaining_credits' => 80]);

        $this->adminPage()
            ->assertOk()
            ->assertSee('data-sms-balance-alert="low"', escape: false)
            ->assertSee(__('filament.sms_balance.alert.low', ['remaining' => '80', 'quota' => '1,000']));
    }

    public function test_an_empty_balance_is_the_alert_even_near_expiry(): void
    {
        $this->balance(['remaining_credits' => 0, 'expires_at' => '2026-11-06T10:00:00+00:00']);

        $this->adminPage()->assertOk()->assertSee('data-sms-balance-alert="empty"', escape: false);
    }

    public function test_a_plan_close_to_expiry_shows_a_warning(): void
    {
        $this->balance(['expires_at' => '2026-11-08T10:00:00+00:00']);

        $this->assertSame(SmsBalance::ALERT_EXPIRING, app(ZadxBalanceChecker::class)->current()->alert());
    }

    public function test_no_active_plan_or_an_expired_one_is_the_most_urgent(): void
    {
        $this->assertSame(SmsBalance::ALERT_NO_PLAN, SmsBalance::fromResponse([
            'has_active_plan' => false, 'remaining_credits' => 0, 'quota' => 0,
        ])->alert());

        $this->assertSame(SmsBalance::ALERT_NO_PLAN, SmsBalance::fromResponse([
            'has_active_plan' => true, 'remaining_credits' => 500, 'quota' => 1000,
            'expires_at' => '2026-11-01T10:00:00+00:00',
        ])->alert());
    }

    public function test_zadx_failing_never_breaks_the_panel(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['code' => 'invalid_credentials']], 401)]);

        $this->adminPage()->assertOk()->assertDontSee('data-sms-balance-alert', escape: false);

        Livewire::actingAs($this->admin)
            ->test(SmsBalanceWidget::class)
            ->assertOk()
            ->assertSee(__('filament.sms_balance.unavailable'));
    }

    public function test_nothing_is_shown_or_requested_without_credentials(): void
    {
        config(['services.zadx.api_key' => null]);
        Http::fake();

        $this->assertFalse(SmsBalanceWidget::canView());
        $this->adminPage()->assertOk()->assertDontSee('data-sms-balance-alert', escape: false);

        Http::assertNothingSent();
    }
}
