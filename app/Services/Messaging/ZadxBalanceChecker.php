<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the SMS balance from ZADX for the admin panel.
 *
 * Cached for a few minutes: the banner shows on every admin page, and the
 * balance only moves as codes are sent. A failed read is cached briefly too,
 * so an outage at ZADX does not slow every page down while it lasts.
 *
 * Never throws. The panel must open whatever ZADX is doing.
 */
class ZadxBalanceChecker
{
    private const CACHE_KEY = 'zadx.sms_balance';

    private const FAILURE_SECONDS = 60;

    /** Whether the credentials are there at all. Without them there is nothing to show. */
    public function isConfigured(): bool
    {
        return $this->base() !== ''
            && (string) config('services.zadx.api_key') !== ''
            && (string) config('services.zadx.api_secret') !== '';
    }

    /**
     * The current balance, or null when ZADX is not configured or could not
     * be read.
     */
    public function current(): ?SmsBalance
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached['ok'] ? SmsBalance::fromResponse($cached['body']) : null;
        }

        $body = $this->fetch();

        Cache::put(
            self::CACHE_KEY,
            ['ok' => $body !== null, 'body' => $body ?? []],
            $body !== null ? now()->addMinutes((int) config('services.zadx.balance.cache_minutes')) : now()->addSeconds(self::FAILURE_SECONDS),
        );

        return $body === null ? null : SmsBalance::fromResponse($body);
    }

    /** Drops the cached reading, after a top-up for instance. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(): ?array
    {
        try {
            $response = Http::withHeaders([
                'X-Api-Key' => (string) config('services.zadx.api_key'),
                'X-Api-Secret' => (string) config('services.zadx.api_secret'),
            ])
                ->acceptJson()
                ->timeout(min(5, (int) config('services.zadx.timeout', 15)))
                ->get($this->base().'/sms/balance');
        } catch (Throwable $e) {
            Log::warning('ZADX balance could not be read: '.$e->getMessage());

            return null;
        }

        if ($response->failed() || ! is_array($response->json())) {
            Log::warning('ZADX balance could not be read: HTTP '.$response->status());

            return null;
        }

        return $response->json();
    }

    private function base(): string
    {
        return rtrim((string) config('services.zadx.base_url'), '/');
    }
}
