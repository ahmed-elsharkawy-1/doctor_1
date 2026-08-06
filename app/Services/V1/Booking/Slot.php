<?php

namespace App\Services\V1\Booking;

use Illuminate\Support\Carbon;

/**
 * One candidate start time, with whether it can actually be booked.
 *
 * The API returns unavailable slots too — the UI greys and strikes them
 * through rather than hiding them (SPEC §5.1).
 */
final class Slot
{
    public function __construct(
        public readonly Carbon $startAt,
        public readonly Carbon $endAt,
        public readonly bool $isAvailable,
    ) {}

    public function unavailable(): self
    {
        return new self($this->startAt, $this->endAt, false);
    }
}
