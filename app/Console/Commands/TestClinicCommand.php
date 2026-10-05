<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Support\TestClinic;
use Illuminate\Console\Command;

/**
 * Brings the test clinic to its shared identity: "د. سارة أحمد" at
 * /dr-sara-ahmed with the shared logins. Safe on production — it renames and
 * sets flags, and never creates or deletes bookings or patients.
 */
class TestClinicCommand extends Command
{
    protected $signature = 'clinic:test-clinic
                            {--clinic= : The clinic to make the test clinic, when it cannot be found by itself}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Give the test clinic its shared name, address and logins on this environment';

    public function handle(): int
    {
        $clinic = $this->option('clinic') ? Clinic::find($this->option('clinic')) : TestClinic::find();

        if ($clinic === null) {
            $this->error('No single clinic plays the test clinic here. Pass --clinic=<id>.');

            return self::FAILURE;
        }

        $this->line("Clinic {$clinic->id}: {$clinic->name} (/{$clinic->slug}) → ".TestClinic::NAME.' (/'.TestClinic::SLUG.')');

        if (! $this->option('force') && ! $this->confirm('Apply the test clinic identity and logins to this clinic?')) {
            return self::FAILURE;
        }

        TestClinic::apply($clinic);
        $clinic->refresh();

        $this->table(['', 'Now'], [
            ['Clinic', "{$clinic->name} (/{$clinic->slug})"],
            ['Test clinic / reports', ($clinic->is_test ? 'yes' : 'no').' / '.($clinic->reports_enabled ? 'on' : 'off')],
            ['Doctor login', TestClinic::DOCTOR_EMAIL.' / '.TestClinic::DOCTOR_PASSWORD],
            ['Assistant login', TestClinic::ASSISTANT_EMAIL.' / '.TestClinic::ASSISTANT_PASSWORD],
            ['Bookings kept', $clinic->bookings()->count()],
        ]);

        return self::SUCCESS;
    }
}
