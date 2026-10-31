<?php

namespace App\Services\V1\Queue;

use Illuminate\Support\Carbon;

/**
 * Where a patient stands in today's queue, as shown on the tracking page.
 *
 * `ahead` is the count the patient reads as عدد الإنتظار: earlier appointments
 * not yet gone in to the doctor, arrived or not, and emergencies in the
 * clinic (see QueuePositionService). An emergency arriving can push it up —
 * the design says so in writing, and `expectedAt` is what makes that
 * tolerable. Nobody turning up early ever does.
 *
 * `aheadNormal` and `aheadEmergency` split `ahead` — they are what the page's
 * tiles show, so a patient reads about their own wait and never about the
 * clinic's whole day. `total`, `normal` and `emergency` cover the whole line
 * still to go in, plus whoever is in the room; they drive the ring's fill and
 * are not shown as numbers. An emergency not yet in the clinic is in none of
 * them.
 *
 * `doctorBusy`: someone else is with the doctor right now. The patient with
 * the doctor is not ahead of anyone, so nobody ahead is not yet "go in".
 * `withDoctor` is how many — one, unless a visit was never marked done.
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
        public readonly int $aheadNormal = 0,
        public readonly int $aheadEmergency = 0,
        public readonly bool $doctorBusy = false,
        public readonly int $withDoctor = 0,
    ) {}

    /**
     * Their place in line, counting from one: the patients ahead, then them.
     */
    public function place(): int
    {
        return $this->ahead + 1;
    }

    /**
     * Everyone else in the line. Whoever is neither with the doctor nor ahead
     * is behind, so with-doctor, place and behind add up to `total`.
     */
    public function behind(): int
    {
        return $this->total - $this->withDoctor - $this->ahead - 1;
    }

    /**
     * The clinic is ready for this patient *now* — the page swaps to دورك الآن
     * and tells them to go to the examination room.
     *
     * Being first in the queue is not enough: the first booking of the day is
     * first from midnight onwards, and telling someone at home at 5am to walk
     * into the examination room for a 1pm appointment is worse than saying
     * nothing. They have to be in the clinic for it to be their turn — and the
     * room has to be free.
     */
    public function isNext(): bool
    {
        return $this->ahead === 0 && $this->hasArrived && ! $this->doctorBusy;
    }

    /**
     * In the clinic with nobody ahead, but the doctor is still with someone:
     * they go in as soon as that visit ends.
     */
    public function isUpNext(): bool
    {
        return $this->ahead === 0 && $this->hasArrived && $this->doctorBusy;
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
