<?php

namespace App\Services\Messaging;

use App\Models\Clinic;

/**
 * How a one-time code reaches a phone.
 *
 * A driver for the same reason MessageSender is one: the whole booking flow
 * has to work end to end before Meta approves anything, and it did — the
 * entire messaging feature shipped and stayed testable against `log` while a
 * template sat in review. Wiring verification straight to WhatsApp would put
 * that approval back on the critical path.
 *
 * The plain code is passed, never the hash: only the sender needs to know it,
 * and the database keeps nothing that could be replayed.
 */
interface OtpSender
{
    public function send(Clinic $clinic, string $phone, string $code): void;
}
