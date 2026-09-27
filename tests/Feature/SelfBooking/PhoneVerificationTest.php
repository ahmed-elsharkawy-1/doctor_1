<?php

namespace Tests\Feature\SelfBooking;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\PhoneVerification;
use App\Services\Messaging\LogOtpSender;
use App\Services\Messaging\OtpSender;
use App\Services\V1\Patients\PhoneVerificationService;
use App\Support\VerifiedPhoneSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingOtpSender;
use Tests\TestCase;

/**
 * Proving somebody owns the phone a booking will be saved against.
 *
 * Why this matters more than it looks: patients are matched on phone alone, so
 * an unverified number does not merely misdirect a message — it files a visit
 * into the wrong person's medical history. A husband booking for his wife
 * types *her* number, and it is hers that has to be proved.
 */
class PhoneVerificationTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const PHONE = '01012225521';

    private const E164 = '+201012225521';

    /** Codes captured instead of sent, so the test can read them. */
    private RecordingOtpSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-21 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $this->sender = new RecordingOtpSender;
        $this->app->instance(OtpSender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Issuing a code
    |--------------------------------------------------------------------------
    */

    public function test_requesting_a_code_stores_it_hashed_and_sends_the_plain_one(): void
    {
        $verification = $this->service()->request($this->clinic, self::PHONE);

        $this->assertSame(self::E164, $verification->phone);
        $this->assertCount(1, $this->sender->sent);
        $this->assertSame(self::E164, $this->sender->sent[0]['phone']);

        $code = $this->sender->lastCode();

        // Length comes from config, not a literal: the rule under test is
        // "digits, exactly as many as configured", not "six".
        $this->assertMatchesRegularExpression(
            '/^\d{'.config('clinic.self_booking.otp.length').'}$/',
            $code,
        );
        // The stored value must never be the code itself.
        $this->assertNotSame($code, $verification->code_hash);
    }

    /**
     * A Saudi clinic's number parsed as an Egyptian one is a different number,
     * and every cap below would then count it separately.
     */
    public function test_the_number_is_normalised_against_the_clinics_own_country(): void
    {
        $this->service()->request($this->clinic, '0101 222 5521');

        $this->assertDatabaseHas('phone_verifications', ['phone' => self::E164]);
    }

    public function test_an_unparseable_number_is_refused_before_anything_is_sent(): void
    {
        try {
            $this->service()->request($this->clinic, 'not-a-phone');
            $this->fail('A nonsense number must not be accepted.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::INVALID_PHONE_NUMBER, $e->errorCode);
        }

        $this->assertSame(0, PhoneVerification::count());
        $this->assertCount(0, $this->sender->sent);
    }

    /*
    |--------------------------------------------------------------------------
    | Checking a code
    |--------------------------------------------------------------------------
    */

    public function test_the_right_code_verifies_the_number(): void
    {
        $this->service()->request($this->clinic, self::PHONE);

        $verified = $this->service()->verify($this->clinic, self::PHONE, $this->sender->lastCode());

        $this->assertNotNull($verified->verified_at);
    }

    public function test_a_wrong_code_is_refused_and_costs_a_guess(): void
    {
        $verification = $this->service()->request($this->clinic, self::PHONE);

        try {
            $this->service()->verify($this->clinic, self::PHONE, '000000');
            $this->fail('A wrong code must be refused.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_INVALID, $e->errorCode);
        }

        $this->assertSame(1, $verification->fresh()->attempts);
        $this->assertNull($verification->fresh()->verified_at);
    }

    /**
     * The guesses belong to the code, not to the phone: burning one is not a
     * lockout, it just means asking for another.
     */
    public function test_the_right_code_no_longer_works_once_the_guesses_are_spent(): void
    {
        $this->service()->request($this->clinic, self::PHONE);
        $code = $this->sender->lastCode();

        for ($attempt = 0; $attempt < (int) config('clinic.self_booking.otp.max_attempts'); $attempt++) {
            try {
                $this->service()->verify($this->clinic, self::PHONE, '000000');
            } catch (ApiException) {
                // Expected — spending the guesses.
            }
        }

        try {
            $this->service()->verify($this->clinic, self::PHONE, $code);
            $this->fail('A burned code must not work even when right.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_TOO_MANY_ATTEMPTS, $e->errorCode);
        }
    }

    public function test_a_code_past_its_life_is_refused(): void
    {
        $this->service()->request($this->clinic, self::PHONE);
        $code = $this->sender->lastCode();

        Carbon::setTestNow(Carbon::now()->addMinutes(
            (int) config('clinic.self_booking.otp.ttl_minutes') + 1,
        ));

        try {
            $this->service()->verify($this->clinic, self::PHONE, $code);
            $this->fail('An expired code must not work.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_EXPIRED, $e->errorCode);
        }
    }

    public function test_a_code_cannot_be_spent_twice(): void
    {
        $this->service()->request($this->clinic, self::PHONE);
        $code = $this->sender->lastCode();

        $this->service()->verify($this->clinic, self::PHONE, $code);

        try {
            $this->service()->verify($this->clinic, self::PHONE, $code);
            $this->fail('A used code must not work a second time.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_EXPIRED, $e->errorCode);
        }
    }

    /**
     * Asking for a second code is the normal way out of a message that never
     * arrived. Leaving the first alive would quietly double the guesses.
     */
    public function test_asking_for_a_new_code_retires_the_old_one(): void
    {
        $this->service()->request($this->clinic, self::PHONE);
        $first = $this->sender->lastCode();

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $this->service()->request($this->clinic, self::PHONE);
        $second = $this->sender->lastCode();

        try {
            $this->service()->verify($this->clinic, self::PHONE, $first);
            $this->fail('The superseded code must not work.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_INVALID, $e->errorCode);
        }

        $this->assertNotNull(
            $this->service()->verify($this->clinic, self::PHONE, $second)->verified_at,
        );
    }

    public function test_a_number_verified_at_one_clinic_is_not_verified_at_another(): void
    {
        $other = $this->otherClinic();

        $this->service()->request($this->clinic, self::PHONE);
        $code = $this->sender->lastCode();

        try {
            $this->service()->verify($other, self::PHONE, $code);
            $this->fail('A code must not cross clinics.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_EXPIRED, $e->errorCode);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Holding the sending down
    |--------------------------------------------------------------------------
    */

    public function test_a_second_code_inside_the_cooldown_is_refused(): void
    {
        $this->service()->request($this->clinic, self::PHONE);

        try {
            $this->service()->request($this->clinic, self::PHONE);
            $this->fail('A resend inside the cooldown must be refused.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_RATE_LIMITED, $e->errorCode);
        }

        $this->assertCount(1, $this->sender->sent);
    }

    public function test_a_resend_is_allowed_once_the_cooldown_has_passed(): void
    {
        $this->service()->request($this->clinic, self::PHONE);

        Carbon::setTestNow(Carbon::now()->addSeconds(
            (int) config('clinic.self_booking.otp.resend_cooldown') + 1,
        ));

        $this->service()->request($this->clinic, self::PHONE);

        $this->assertCount(2, $this->sender->sent);
    }

    public function test_one_number_cannot_be_messaged_past_its_hourly_cap(): void
    {
        $cap = (int) config('clinic.self_booking.otp.max_per_phone_hour');

        for ($sent = 0; $sent < $cap; $sent++) {
            $this->service()->request($this->clinic, self::PHONE);
            Carbon::setTestNow(Carbon::now()->addSeconds(61));
        }

        try {
            $this->service()->request($this->clinic, self::PHONE);
            $this->fail('The hourly cap must hold.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_RATE_LIMITED, $e->errorCode);
        }

        $this->assertCount($cap, $this->sender->sent);
    }

    public function test_the_hourly_cap_lets_go_after_an_hour(): void
    {
        $cap = (int) config('clinic.self_booking.otp.max_per_phone_hour');

        for ($sent = 0; $sent < $cap; $sent++) {
            $this->service()->request($this->clinic, self::PHONE);
            Carbon::setTestNow(Carbon::now()->addSeconds(61));
        }

        Carbon::setTestNow(Carbon::now()->addHour());

        $this->service()->request($this->clinic, self::PHONE);

        $this->assertCount($cap + 1, $this->sender->sent);
    }

    /**
     * The cap that actually stops abuse: without it a public form that sends
     * messages is a way to bill us and to pester a hundred strangers.
     */
    public function test_one_address_cannot_message_a_hundred_strangers(): void
    {
        $cap = (int) config('clinic.self_booking.otp.max_per_ip_hour');

        for ($sent = 0; $sent < $cap; $sent++) {
            $this->service()->request($this->clinic, '0101222'.str_pad((string) $sent, 4, '0', STR_PAD_LEFT), '10.0.0.1');
        }

        try {
            $this->service()->request($this->clinic, '01099998888', '10.0.0.1');
            $this->fail('The per-address cap must hold.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::OTP_RATE_LIMITED, $e->errorCode);
        }

        // A different address is unaffected.
        $this->service()->request($this->clinic, '01099998888', '10.0.0.2');

        $this->assertCount($cap + 1, $this->sender->sent);
    }

    /*
    |--------------------------------------------------------------------------
    | How long the proof lasts
    |--------------------------------------------------------------------------
    */

    public function test_a_verified_browser_is_remembered_for_a_while(): void
    {
        $session = app(VerifiedPhoneSession::class);

        $session->remember($this->clinic, self::E164);

        $this->assertTrue($session->isVerified($this->clinic));
        $this->assertSame(self::E164, $session->phoneFor($this->clinic));
        $this->assertTrue($session->matches($this->clinic, self::E164));
    }

    /**
     * Checked against the clock rather than the session's own lifetime, which
     * is a global a deploy could lengthen without anyone connecting it to
     * verification.
     */
    public function test_the_proof_goes_stale_on_its_own(): void
    {
        $session = app(VerifiedPhoneSession::class);

        $session->remember($this->clinic, self::E164);

        Carbon::setTestNow(Carbon::now()->addMinutes(
            (int) config('clinic.self_booking.verified_session_minutes') + 1,
        ));

        $this->assertFalse($session->isVerified($this->clinic));
        $this->assertNull($session->phoneFor($this->clinic));
    }

    /**
     * Typing a different number is a different claim. Otherwise the husband
     * who verified his wife's phone could swap in a stranger's at the end.
     */
    public function test_the_proof_is_for_one_number_only(): void
    {
        $session = app(VerifiedPhoneSession::class);

        $session->remember($this->clinic, self::E164);

        $this->assertFalse($session->matches($this->clinic, '+201099998888'));
    }

    public function test_the_proof_does_not_cross_clinics(): void
    {
        $session = app(VerifiedPhoneSession::class);
        $other = $this->otherClinic();

        $session->remember($this->clinic, self::E164);

        $this->assertFalse($session->isVerified($other));
    }

    /*
    |--------------------------------------------------------------------------
    | Housekeeping
    |--------------------------------------------------------------------------
    */

    public function test_stale_rows_are_swept_but_recent_ones_are_kept(): void
    {
        $this->service()->request($this->clinic, self::PHONE);

        PhoneVerification::factory()->forPhone($this->clinic, '+201055556666')->create([
            'created_at' => Carbon::now()->subDays(2),
        ]);

        $this->assertSame(1, $this->service()->purgeStale());
        $this->assertSame(1, PhoneVerification::count());
    }

    /*
    |--------------------------------------------------------------------------
    | The fixed code, for walking the flow by hand
    |--------------------------------------------------------------------------
    */

    public function test_a_pinned_code_is_used_instead_of_a_random_one(): void
    {
        config(['clinic.self_booking.otp.fixed_code' => '123456']);

        $this->service()->request($this->clinic, self::PHONE);

        $this->assertSame('123456', $this->sender->lastCode());
        $this->assertNotNull(
            $this->service()->verify($this->clinic, self::PHONE, '123456')->verified_at,
        );
    }

    /** Still hashed — a pinned code is a convenience, not a different path. */
    public function test_a_pinned_code_is_stored_like_any_other(): void
    {
        config(['clinic.self_booking.otp.fixed_code' => '123456']);

        $verification = $this->service()->request($this->clinic, self::PHONE);

        $this->assertNotSame('123456', $verification->code_hash);
    }

    public function test_a_short_pinned_code_is_padded_to_the_configured_length(): void
    {
        config([
            'clinic.self_booking.otp.length' => 6,
            'clinic.self_booking.otp.fixed_code' => '42',
        ]);

        $this->service()->request($this->clinic, self::PHONE);

        // Pinned here rather than read back from config: padding is the
        // behaviour under test, so the expected string has to be written out.
        $this->assertSame('000042', $this->sender->lastCode());
    }

    /**
     * The production guard has one deliberate way past it, and it does not
     * extend to a code everybody knows.
     */
    public function test_the_log_driver_can_be_opened_on_a_production_host(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        config(['clinic.self_booking.otp.allow_log_in_production' => false]);
        $this->expectException(\RuntimeException::class);
        (new LogOtpSender)->send($this->clinic, self::E164, '4321');
    }

    public function test_opening_it_does_not_also_allow_a_fixed_code(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        config([
            'clinic.self_booking.otp.allow_log_in_production' => true,
            'clinic.self_booking.otp.fixed_code' => '1234',
        ]);

        // The log driver is permitted now...
        (new LogOtpSender)->send($this->clinic, self::E164, '4321');

        // ...but a code a stranger could guess is still refused.
        $this->expectException(\RuntimeException::class);
        $this->service()->request($this->clinic, self::PHONE);
    }

    /**
     * Refused rather than ignored. Falling back to a random code would hide
     * the misconfiguration until it was the only thing between a stranger and
     * somebody's medical record.
     */
    public function test_a_pinned_code_is_refused_in_production(): void
    {
        config(['clinic.self_booking.otp.fixed_code' => '123456']);
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);

        $this->service()->request($this->clinic, self::PHONE);
    }

    /**
     * A code written to a log is a code anybody with log access can use, and
     * the entire point of this mechanism is proving a stranger is not the
     * person they claim to be. Better to fail loudly than to ship it.
     */
    public function test_the_log_driver_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);

        (new LogOtpSender)->send($this->clinic, self::E164, '123456');
    }

    private function service(): PhoneVerificationService
    {
        return app(PhoneVerificationService::class);
    }
}
