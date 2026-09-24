<?php

namespace App\Services\Results\V1\Booking;

use App\Models\SlotHold;
use App\Services\Results\ServiceResult;
use App\Support\Wire;

/**
 * A slot hold on the wire.
 *
 * The token is the one field the client genuinely needs back: it is what lets
 * the same caller book the slot it is sitting on, and what moves the claim
 * when they pick a different time. It is hidden on the model precisely so it
 * only ever travels from here, deliberately.
 */
final class SlotHoldResult extends ServiceResult
{
    public function __construct(private readonly SlotHold $hold) {}

    public function toArray(): array
    {
        return [
            'token' => $this->hold->token,
            'date' => Wire::date($this->hold->visit_date),
            'start_time' => Wire::time($this->hold->start_at),
            'end_time' => Wire::time($this->hold->end_at),
            'visit_type_id' => $this->hold->visit_type_id,
            // When the claim lapses. The client should either book or let go
            // before then; nothing has to be called to make it expire.
            'expires_at' => $this->hold->expires_at->toAtomString(),
            // Carbon reads "other minus this", so this is time remaining.
            'expires_in_seconds' => max(0, (int) round(
                now()->diffInSeconds($this->hold->expires_at, absolute: false),
            )),
        ];
    }
}
