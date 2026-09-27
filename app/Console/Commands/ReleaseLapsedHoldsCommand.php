<?php

namespace App\Console\Commands;

use App\Services\V1\Booking\SlotHoldService;
use Illuminate\Console\Command;

/**
 * Gives back slots whose holder walked away.
 *
 * A hold stops blocking its slot the moment `expires_at` passes — that is a
 * query filter, so correctness has never waited for this command. What waits
 * for it is everybody's *screen*: a lapsed hold is the only way a slot comes
 * free without anybody doing anything, so it is the only case with nobody to
 * announce it. Left unswept, a browser watching that day keeps the slot greyed
 * out until its owner happens to touch the page.
 *
 * Hence every minute rather than with the nightly close-out. The work is a
 * single indexed delete that usually finds nothing; the point is the event it
 * dispatches when it does.
 */
class ReleaseLapsedHoldsCommand extends Command
{
    protected $signature = 'clinic:release-lapsed-holds';

    protected $description = 'Release slot holds whose time is up and tell anyone watching that day';

    public function handle(SlotHoldService $holds): int
    {
        $released = $holds->purgeExpired();

        if ($released > 0) {
            $this->info("Released {$released} lapsed hold(s).");
        }

        return self::SUCCESS;
    }
}
