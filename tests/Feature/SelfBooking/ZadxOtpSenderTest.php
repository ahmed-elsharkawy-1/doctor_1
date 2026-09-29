<?php

namespace Tests\Feature\SelfBooking;

use App\Services\Messaging\OtpSender;
use App\Services\Messaging\ZadxOtpSender;
use App\Services\V1\Booking\PatientBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Sending a one-time code over SMS through ZADX.
 *
 * Written against their published contract before the integration could be
 * exercised for real — their base URL is only shown in the dashboard, and we
 * do not have it yet. So these pin the request we will send and the way each
 * documented failure is read back, and the first live call should be the only
 * surprise left.
 */
class ZadxOtpSenderTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const PHONE = '+201012225521';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();

        config([
            'services.zadx.base_url' => 'https://example.test/api/v1',
            'services.zadx.api_key' => 'pk_test',
            'services.zadx.api_secret' => 'sk_test',
            'services.zadx.sender_id' => 'Elayadah',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The request
    |--------------------------------------------------------------------------
    */

    public function test_it_posts_the_code_to_their_otp_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->queued(), 202)]);

        (new ZadxOtpSender)->send($this->clinic, self::PHONE, '4321');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://example.test/api/v1/otp/send'
                && $request->method() === 'POST'
                && $request['to'] === self::PHONE
                && $request['otp'] === '4321'
                && $request['locale'] === 'ar'
                && $request['sender_id'] === 'Elayadah';
        });
    }

    /** Their docs require both; the credentials are useless apart. */
    public function test_it_sends_both_credentials(): void
    {
        Http::fake(['*' => Http::response($this->queued(), 202)]);

        (new ZadxOtpSender)->send($this->clinic, self::PHONE, '4321');

        Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Api-Key', 'pk_test')
            && $r->hasHeader('X-Api-Secret', 'sk_test'));
    }

    /**
     * A queue retry after a timeout must not charge twice or deliver the code
     * a second time, so the same code carries the same key.
     */
    public function test_the_same_code_carries_the_same_idempotency_key(): void
    {
        Http::fake(['*' => Http::response($this->queued(), 202)]);

        $sender = new ZadxOtpSender;
        $sender->send($this->clinic, self::PHONE, '4321');
        $sender->send($this->clinic, self::PHONE, '4321');

        $keys = [];
        Http::assertSent(function (Request $r) use (&$keys): bool {
            $keys[] = $r->header('Idempotency-Key')[0];

            return true;
        });

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_a_different_code_carries_a_different_key(): void
    {
        Http::fake(['*' => Http::response($this->queued(), 202)]);

        $sender = new ZadxOtpSender;
        $sender->send($this->clinic, self::PHONE, '4321');
        $sender->send($this->clinic, self::PHONE, '8765');

        $keys = [];
        Http::assertSent(function (Request $r) use (&$keys): bool {
            $keys[] = $r->header('Idempotency-Key')[0];

            return true;
        });

        $this->assertNotSame($keys[0], $keys[1]);
    }

    /** Unset, they fall back to the app's own default sender. */
    public function test_no_sender_id_is_sent_when_none_is_configured(): void
    {
        config(['services.zadx.sender_id' => null]);
        Http::fake(['*' => Http::response($this->queued(), 202)]);

        (new ZadxOtpSender)->send($this->clinic, self::PHONE, '4321');

        Http::assertSent(fn (Request $r): bool => ! isset($r['sender_id']));
    }

    /*
    |--------------------------------------------------------------------------
    | Failures, in their words
    |--------------------------------------------------------------------------
    */

    public static function failures(): array
    {
        return [
            'bad credentials' => [401, 'invalid_credentials', 'refused the credentials'],
            'no credit' => [402, 'quota_exhausted', 'no credits left'],
            'sender not assigned' => [403, 'sender_id_not_allowed', 'not assigned this sender ID'],
            'bad number' => [422, 'invalid_phone', 'not a valid Egyptian mobile'],
            'too fast' => [429, 'rate_limited_phone_minute', 'rate limiting'],
        ];
    }

    #[DataProvider('failures')]
    public function test_each_documented_failure_is_readable(int $status, string $code, string $expected): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => $code, 'message' => 'x']], $status)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($expected, '/').'/');

        (new ZadxOtpSender)->send($this->clinic, self::PHONE, '4321');
    }

    /** An unrecognised code still surfaces their own words rather than a bare status. */
    public function test_an_unknown_failure_still_says_what_they_said(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'teapot', 'message' => 'Short and strange']], 418)]);

        $this->expectExceptionMessage('ZADX: Short and strange');

        (new ZadxOtpSender)->send($this->clinic, self::PHONE, '4321');
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * No base URL means no guess. Posting a live verification code at a host
     * we inferred is worse than failing loudly.
     */
    public function test_it_refuses_to_run_without_a_base_url(): void
    {
        config(['services.zadx.base_url' => null]);
        Http::fake();

        $this->expectExceptionMessageMatches('/ZADX_BASE_URL/');

        (new ZadxOtpSender)->send($this->clinic, self::PHONE, '4321');

        Http::assertNothingSent();
    }

    public function test_the_driver_is_selected_by_name(): void
    {
        config(['clinic.self_booking.otp.driver' => 'zadx']);

        $this->assertInstanceOf(ZadxOtpSender::class, app(OtpSender::class));
    }

    /**
     * The copy follows the driver. Hardcoding "WhatsApp" was wrong the day
     * codes moved to SMS, and hardcoding "SMS" would be wrong again the day
     * Meta approves a template.
     */
    public function test_the_copy_names_the_channel_actually_in_use(): void
    {
        app()->setLocale('ar');
        $service = app(PatientBookingService::class);

        config(['clinic.self_booking.otp.driver' => 'zadx']);
        $this->assertSame(__('booking.self_booking.channel_sms'), $service->otpChannel());

        // `log` stands in for SMS while testing — it must not claim WhatsApp,
        // which is what happened when WhatsApp was the default arm.
        config(['clinic.self_booking.otp.driver' => 'log']);
        $this->assertSame(__('booking.self_booking.channel_sms'), $service->otpChannel());

        // Only a driver that really sends over WhatsApp says so.
        config(['clinic.self_booking.otp.driver' => 'cloud_api']);
        $this->assertSame(__('booking.self_booking.channel_whatsapp'), $service->otpChannel());

        // And the key resolves — a missing one renders as its own path.
        $this->assertStringNotContainsString('booking.self_booking', $service->otpChannel());
    }

    private function queued(): array
    {
        return [
            'id' => 4127,
            'status' => 'queued',
            'to' => self::PHONE,
            'sender_id' => 'Elayadah',
            'segments' => 1,
            'cost_credits' => 1,
            'remaining_credits' => 996,
        ];
    }
}
