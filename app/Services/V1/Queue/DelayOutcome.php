<?php

namespace App\Services\V1\Queue;

use App\Models\Booking;
use Illuminate\Support\Collection;

/**
 * What an emergency delay did: who moved, by how much each, and what became
 * of their messages.
 *
 * `replayed` is a repeat of the same tap answered from the first one. Its
 * `messaging` is null when the repeat arrived while the first was still
 * queueing its messages.
 */
final class DelayOutcome
{
    /**
     * @param  Collection<int, Booking>  $bookings  the moved bookings, at their new times
     * @param  array<int, int>  $minutesByBooking  booking id => minutes moved
     * @param  array<string, mixed>|null  $messaging
     */
    public function __construct(
        public readonly Collection $bookings,
        public readonly array $minutesByBooking,
        public readonly ?array $messaging,
        public readonly bool $replayed = false,
    ) {}
}
