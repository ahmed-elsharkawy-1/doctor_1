<?php

namespace Tests\Support;

use App\Models\Clinic;
use App\Services\Messaging\OtpSender;

/**
 * Captures one-time codes instead of sending them.
 *
 * A spy rather than a mock: the tests care what was sent and to whom, and the
 * code has to be readable back so the verification step can actually be
 * exercised — which is the whole point of the flow tests.
 *
 * Bind it with `$this->app->instance(OtpSender::class, $sender)`.
 */
class RecordingOtpSender implements OtpSender
{
    /** @var list<array{phone: string, code: string}> */
    public array $sent = [];

    public function send(Clinic $clinic, string $phone, string $code): void
    {
        $this->sent[] = ['phone' => $phone, 'code' => $code];
    }

    public function lastCode(): string
    {
        return $this->sent[array_key_last($this->sent)]['code'];
    }
}
