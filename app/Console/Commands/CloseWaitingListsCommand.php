<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\V1\WaitingList\WaitingListService;
use Illuminate\Console\Command;

/**
 * Closes each waiting list once its day's last period has ended, and tells
 * whoever is still on it that no slot opened.
 *
 * Gated on the clinic's own schedule rather than a fixed hour, so an evening
 * clinic is not told at 6pm and an overnight one not at midnight. Idempotent:
 * a closed entry is off the list, and each is messaged at most once.
 *
 * Every active clinic, not only those with the list switched on: a clinic
 * switched off with patients still on a list should still close the loop.
 */
class CloseWaitingListsCommand extends Command
{
    protected $signature = 'clinic:close-waiting-lists
                            {--clinic= : Limit to one clinic id}';

    protected $description = 'Close waiting lists whose day has ended and tell the patients still on them';

    public function handle(WaitingListService $waitingList): int
    {
        $clinics = Clinic::query()
            ->when($this->option('clinic'), fn ($query, $id) => $query->whereKey($id))
            ->where('is_active', true)
            ->whereHas('waitingListEntries', fn ($query) => $query->waiting())
            ->get();

        $closed = 0;
        $messaged = 0;

        foreach ($clinics as $clinic) {
            $outcome = $waitingList->closeEndedDays($clinic);
            $closed += $outcome['closed'];
            $messaged += $outcome['messaged'];
        }

        $this->info("Closed {$closed} waiting-list entr(y/ies); messaged {$messaged}.");

        return self::SUCCESS;
    }
}
