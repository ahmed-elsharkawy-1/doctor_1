<?php

namespace App\Services\V1\Queue;

use Illuminate\Support\Carbon;

/**
 * Where a patient stands in today's queue, as shown on the tracking page.
 *
 * `ahead` is the count the patient reads as عدد الإنتظار. It counts everyone
 * ranked before this booking who has not been seen yet — including patients
 * who have not arrived — so an emergency arriving later can push it up. The
 * design says so in writing, and `expectedAt` is what makes that tolerable.
 */
final class QueuePosition
{
    public function __construct(
        public readonly int $ahead,
        public readonly int $total,
        public readonly int $normal,
        public readonly int $emergency,
        public readonly ?Carbon $expectedAt,
        public readonly bool $hasArrived = false,
    ) {}

    /**
     * The clinic is ready for this patient *now* — the page swaps to دورك الآن
     * and tells them to go to the examination room.
     *
     * Being first in the queue is not enough: the first booking of the day is
     * first from midnight onwards, and telling someone at home at 5am to walk
     * into the examination room for a 1pm appointment is worse than saying
     * nothing. They have to be in the clinic for it to be their turn.
     */
    public function isNext(): bool
    {
        return $this->ahead === 0 && $this->hasArrived;
    }

    /**
     * Nobody is booked before them, but they are not in the clinic yet — so
     * the page reassures them without calling them in.
     */
    public function isFirstInLine(): bool
    {
        return $this->ahead === 0 && ! $this->hasArrived;
    }
}
