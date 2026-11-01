<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\V1\Patients\OnlineBookingService;
use Illuminate\Console\Command;

/**
 * Applies the no-show rule to what already happened.
 *
 * The rule runs as each no-show is marked, so a clinic switched on today
 * would otherwise wait for the next no-show before blocking a patient who
 * already qualifies. Run once when a clinic's rule is switched on; safe to run
 * again — a blocked patient is never blocked twice, and nobody is unblocked.
 */
class ApplyNoShowBlocksCommand extends Command
{
    protected $signature = 'clinic:apply-no-show-blocks
                            {--clinic= : Limit to one clinic id}
                            {--dry-run : List who would be blocked, change nothing}';

    protected $description = 'Block patients who already reach the no-show limit, at clinics with the rule on';

    public function handle(OnlineBookingService $onlineBooking): int
    {
        $clinics = Clinic::query()
            ->when($this->option('clinic'), fn ($query, $id) => $query->whereKey($id))
            ->where('is_active', true)
            ->where('no_show_block_enabled', true)
            ->orderBy('id')
            ->get();

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($clinics as $clinic) {
            $patients = $onlineBooking->qualifying($clinic);

            foreach ($patients as $patient) {
                $count = $onlineBooking->countedNoShows($patient);

                if (! $dryRun) {
                    $onlineBooking->checkAfterNoShow($patient);
                }

                $this->line("clinic {$clinic->id} · patient {$patient->id} · {$patient->code} · {$patient->name} · {$count} no-shows");
            }

            $total += $patients->count();
        }

        $this->info(($dryRun ? 'Would block' : 'Blocked')." {$total} patient(s) across {$clinics->count()} clinic(s).");

        return self::SUCCESS;
    }
}
