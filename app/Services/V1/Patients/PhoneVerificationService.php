<?php

namespace App\Services\V1\Patients;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\PhoneVerification;
use App\Services\Messaging\OtpSender;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Proving that somebody owns the phone number a booking will be saved against.
 *
 * The number matters more than it looks: patients are matched on phone alone,
 * so an unverified one does not merely misdirect a message, it files a visit
 * into the wrong person's medical history. The code therefore goes to the
 * number that will attend the appointment, not to whoever happens to be
 * holding the browser.
 *
 * **Rate limits are counted in the table, not through RateLimiter.** The
 * framework's limiter decays against real wall-clock time in the cache, which
 * `Carbon::setTestNow()` cannot move — every cap here would need a sleeping
 * test to cover it. Counting rows is fully controllable from a test, survives
 * a cache flush, and is the same number support would count by hand.
 */
class PhoneVerificationService
{
    public function __construct(private readonly OtpSender $sender) {}

    /**
     * Issues a code and sends it.
     *
     * @param  string  $phone  as typed; normalised here against the clinic's
     *                         own country so the caps count one number once
     * @param  string|null  $ip  the requester, for the per-address cap
     */
    public function request(Clinic $clinic, string $phone, ?string $ip = null): PhoneVerification
    {
        $number = $this->parse($clinic, $phone);

        $this->guardCooldown($clinic, $number->e164);
        $this->guardPhoneCap($clinic, $number->e164);
        $this->guardIpCap($ip);

        $code = $this->newCode();

        $verification = PhoneVerification::create([
            'clinic_id' => $clinic->id,
            'phone' => $number->e164,
            'code_hash' => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(
                (int) config('clinic.self_booking.otp.ttl_minutes'),
            ),
            'ip' => $ip,
        ]);

        // Outside any transaction and after the row exists, so a code that
        // reaches somebody is always one we can still recognise.
        $this->sender->send($clinic, $number->e164, $code);

        return $verification;
    }

    /**
     * Checks a code against the newest one issued for that number.
     *
     * Only the newest counts. Asking for a second code is the normal way out
     * of a message that never arrived, and leaving the earlier one alive would
     * quietly multiply the guesses available.
     */
    public function verify(Clinic $clinic, string $phone, string $code): PhoneVerification
    {
        $number = $this->parse($clinic, $phone);

        $verification = PhoneVerification::query()
            ->forPhone($clinic->id, $number->e164)
            ->latest('id')
            ->first();

        if ($verification === null) {
            throw ApiException::make(
                ApiErrorCode::OTP_EXPIRED,
                __('patient.otp.expired'),
            );
        }

        if ($verification->verified_at !== null || $verification->hasExpired()) {
            throw ApiException::make(
                ApiErrorCode::OTP_EXPIRED,
                __('patient.otp.expired'),
            );
        }

        if ($verification->isExhausted()) {
            throw ApiException::make(
                ApiErrorCode::OTP_TOO_MANY_ATTEMPTS,
                __('patient.otp.too_many_attempts'),
            );
        }

        if (! Hash::check($code, $verification->code_hash)) {
            // Counted before the refusal, so a wrong guess costs the same
            // whether or not the caller waits for the answer.
            $verification->increment('attempts');

            throw ApiException::make(
                ApiErrorCode::OTP_INVALID,
                __('patient.otp.invalid'),
                details: ['attempts_left' => max(0, $this->maxAttempts() - $verification->attempts)],
            );
        }

        $verification->update(['verified_at' => Carbon::now()]);

        return $verification->refresh();
    }

    /**
     * Housekeeping for the nightly command.
     *
     * These rows hold a phone number with no patient attached, which is the
     * only place in the system that is true — so they do not linger once they
     * are past the window the caps count.
     */
    public function purgeStale(): int
    {
        return PhoneVerification::where('created_at', '<', Carbon::now()->subDay())->delete();
    }

    /**
     * The E.164 form, or the same refusal the OTP path would give.
     *
     * Public so a flow that skips verification still rejects a number the
     * verified flow would have rejected — one parser, one error, one contract.
     */
    public function normalise(Clinic $clinic, string $phone): string
    {
        return $this->parse($clinic, $phone)->e164;
    }

    /**
     * Normalised against the clinic's own country: a Saudi clinic's number
     * parsed as an Egyptian one is a different number, and the caps would
     * count it separately.
     */
    private function parse(Clinic $clinic, string $phone): PhoneNumber
    {
        try {
            return PhoneNumber::parse($phone, $clinic->country_code);
        } catch (\InvalidArgumentException) {
            throw ApiException::make(
                ApiErrorCode::INVALID_PHONE_NUMBER,
                __('patient.invalid_phone'),
                details: ['phone' => $phone],
                http: 422,
            );
        }
    }

    /**
     * Stops a jammed button turning into a stream of messages at somebody.
     */
    private function guardCooldown(Clinic $clinic, string $phone): void
    {
        $cooldown = (int) config('clinic.self_booking.otp.resend_cooldown');

        $recent = PhoneVerification::query()
            ->forPhone($clinic->id, $phone)
            ->where('created_at', '>', Carbon::now()->subSeconds($cooldown))
            ->exists();

        if ($recent) {
            throw ApiException::make(
                ApiErrorCode::OTP_RATE_LIMITED,
                __('patient.otp.cooldown', ['seconds' => $cooldown]),
                http: 429,
            );
        }
    }

    private function guardPhoneCap(Clinic $clinic, string $phone): void
    {
        $cap = (int) config('clinic.self_booking.otp.max_per_phone_hour');

        $sent = PhoneVerification::query()
            ->forPhone($clinic->id, $phone)
            ->where('created_at', '>', Carbon::now()->subHour())
            ->count();

        if ($sent >= $cap) {
            throw ApiException::make(
                ApiErrorCode::OTP_RATE_LIMITED,
                __('patient.otp.rate_limited'),
                http: 429,
            );
        }
    }

    /**
     * The one that matters for abuse. Without it, a public form that sends
     * messages is a way to bill us and to pester a hundred strangers.
     */
    private function guardIpCap(?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $cap = (int) config('clinic.self_booking.otp.max_per_ip_hour');

        $sent = PhoneVerification::query()
            ->where('ip', $ip)
            ->where('created_at', '>', Carbon::now()->subHour())
            ->count();

        if ($sent >= $cap) {
            throw ApiException::make(
                ApiErrorCode::OTP_RATE_LIMITED,
                __('patient.otp.rate_limited'),
                http: 429,
            );
        }
    }

    /**
     * A fresh code — unless this environment has pinned one for testing.
     *
     * The pin is refused in production rather than ignored there. A known
     * code is no check at all, and silently falling back to a random one
     * would hide a misconfiguration that looks fine right up until it is
     * the only thing standing between a stranger and somebody's medical
     * record.
     */
    private function newCode(): string
    {
        $length = (int) config('clinic.self_booking.otp.length');
        $fixed = config('clinic.self_booking.otp.fixed_code');

        if (filled($fixed)) {
            if (app()->isProduction() && ! config('clinic.self_booking.otp.allow_fixed_in_production')) {
                throw new \RuntimeException(
                    'CLINIC_OTP_FIXED_CODE is set in production. A fixed verification '
                    .'code means anybody can book with anybody\'s number. Unset it.',
                );
            }

            return str_pad((string) $fixed, $length, '0', STR_PAD_LEFT);
        }

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function maxAttempts(): int
    {
        return (int) config('clinic.self_booking.otp.max_attempts');
    }
}
