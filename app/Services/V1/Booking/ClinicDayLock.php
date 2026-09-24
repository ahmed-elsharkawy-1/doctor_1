<?php

namespace App\Services\V1\Booking;

use App\Models\Clinic;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one lock that serialises every write competing for a clinic's day.
 *
 * Two tabs, a double-tap, or a patient and the secretary reaching for the same
 * time in the same second must not both win. The lock serialises writes for one
 * clinic-day; the transaction inside it keeps the availability check and the
 * write atomic.
 *
 * **This class exists so the key lives in one place.** Bookings and slot holds
 * both claim time, so both have to queue behind the same lock. If either built
 * its own copy of the key and the two ever drifted apart — a rename, a stray
 * space, a different date format — they would stop serialising against each
 * other and double-booking would quietly become possible again, with nothing
 * failing to say so. There is no test that can catch that, which is why there
 * is only one key.
 *
 * The lock is NOT re-entrant. Anything running inside a claim() callback must
 * not claim again: it would wait for a lock it is already holding and time out.
 * That is why SlotHoldService keeps its unlocked `consume()` separate from its
 * locked `hold()`.
 */
final class ClinicDayLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function claim(Clinic $clinic, Carbon $date, Closure $callback): mixed
    {
        return Cache::lock($this->key($clinic, $date), $this->ttlSeconds())
            ->block($this->waitSeconds(), fn () => DB::transaction($callback));
    }

    /** How long the lock is held before it is considered abandoned. */
    public function ttlSeconds(): int
    {
        return (int) config('clinic.locking.day_lock_ttl_seconds');
    }

    /**
     * How long a caller waits for someone else to finish.
     *
     * Worth knowing when writing a test that makes two writers contend: the
     * lock measures this against the clock, and `Carbon::setTestNow()` stops
     * the clock — so a frozen test that really contends will spin rather than
     * time out. Let time run for the duration, and keep the wait short.
     */
    public function waitSeconds(): int
    {
        return (int) config('clinic.locking.day_lock_wait_seconds');
    }

    /**
     * Public so it can be asserted against — the whole point of this class is
     * that bookings and holds agree on it.
     */
    public function key(Clinic $clinic, Carbon $date): string
    {
        return "booking_lock_{$clinic->id}_{$date->toDateString()}";
    }
}
