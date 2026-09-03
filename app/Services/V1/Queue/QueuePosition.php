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
    ) {}

    /**
     * The patient is next — the page swaps to دورك الأن.
     */
    public function isNext(): bool
    {
        return $this->ahead === 0;
    }
}
