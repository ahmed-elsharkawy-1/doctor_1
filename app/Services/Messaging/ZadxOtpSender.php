<?php

namespace App\Services\Messaging;

use App\Models\Clinic;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One-time codes over SMS, through ZADX.
 *
 * We generate the code and they deliver it, which is why this fits the same
 * OtpSender interface the log driver uses — nothing above it knows or cares
 * which one is bound.
 *
 * The message body is their approved template. We supply the code and nothing
 * else; free text is not accepted. Same constraint as a WhatsApp
 * authentication template, and for the same regulatory reason.
 */
class ZadxOtpSender implements OtpSender
{
    public function send(Clinic $clinic, string $phone, string $code, string $reference): void
    {
        $base = rtrim((string) config('services.zadx.base_url'), '/');
        $key = (string) config('services.zadx.api_key');
        $secret = (string) config('services.zadx.api_secret');

        if ($base === '' || $key === '' || $secret === '') {
            throw new RuntimeException(
                'The ZADX OTP driver is selected but ZADX_BASE_URL, ZADX_API_KEY '
                .'or ZADX_API_SECRET is not set.',
            );
        }

        $response = Http::withHeaders([
            'X-Api-Key' => $key,
            'X-Api-Secret' => $secret,
            'Idempotency-Key' => $this->idempotencyKey($clinic, $phone, $reference),
        ])
            ->acceptJson()
            ->timeout((int) config('services.zadx.timeout', 15))
            ->post($base.'/otp/send', array_filter([
                'to' => $phone,
                'otp' => $code,
                'locale' => 'ar',
                'sender_id' => config('services.zadx.sender_id') ?: null,
            ]));

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->status(), $response->json()));
        }
    }

    /**
     * Derived from the issued code's reference, so a retry of the *same* send
     * is free and every new code is a new send.
     *
     * ZADX returns the original response for a repeated key, and 409s if the
     * key comes back with different content — so this must change exactly when
     * the message does, and not otherwise. A retry after a timeout is the case
     * the key exists for: without it that would charge twice and deliver two
     * identical messages.
     *
     * Not the digits. It was, and a new code that happened to repeat the last
     * one's four digits was answered "queued" and never sent.
     */
    private function idempotencyKey(Clinic $clinic, string $phone, string $reference): string
    {
        return 'otp-'.hash('sha256', implode('|', [$clinic->id, $phone, $reference]));
    }

    /**
     * Their words where they have them.
     *
     * The codes are specific in ways a status is not — an unassigned sender ID
     * and an unknown template are both 403/422, and only the code tells them
     * apart when somebody is reading the logs a week later.
     */
    private function reason(int $status, mixed $body): string
    {
        $code = is_array($body) ? ($body['error']['code'] ?? null) : null;
        $message = is_array($body) ? ($body['error']['message'] ?? null) : null;

        return match ($code) {
            'invalid_credentials' => 'ZADX refused the credentials (401).',
            'quota_exhausted' => 'ZADX has no credits left to send this code (402).',
            'sender_id_not_allowed' => 'ZADX has not assigned this sender ID to the app (403): '
                .config('services.zadx.sender_id'),
            'invalid_phone' => 'ZADX rejected the number as not a valid Egyptian mobile (422).',
            'rate_limited_phone_minute' => 'ZADX is rate limiting this number (429).',
            default => $message
                ? 'ZADX: '.$message
                : 'ZADX returned HTTP '.$status.'.',
        };
    }
}
