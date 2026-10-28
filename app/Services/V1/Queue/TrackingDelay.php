<?php

namespace App\Services\V1\Queue;

use Illuminate\Support\Carbon;

/**
 * The delay notice on the patient's tracking page: how late the clinic is
 * running for them, their appointment, and when they can now expect to go in.
 *
 * Times are the clinic's wall clock, like every booking time.
 */
final class TrackingDelay
{
    public function __construct(
        public readonly int $minutes,
        public readonly Carbon $bookedAt,
        public readonly Carbon $expectedAt,
        public readonly bool $expectedAfterMidnight,
    ) {}
}
