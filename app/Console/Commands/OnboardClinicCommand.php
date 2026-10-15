<?php

namespace App\Console\Commands;

use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Services\V1\Clinic\ClinicOnboardingService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Adds a real clinic from a JSON description — see ClinicOnboardingService
 * for the format, and docs/onboarding.md for the staging-then-production
 * routine.
 *
 * Shows the plan first and writes nothing without confirmation (or --force,
 * which is how it runs over ssh with the description on stdin).
 */
class OnboardClinicCommand extends Command
{
    protected $signature = 'clinic:onboard
                            {file=- : Path to the JSON description, or "-" to read it from stdin}
                            {--dry-run : Validate and show the plan, write nothing}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Add a real clinic — clinic, doctor, logins and a starting week — from a JSON description';

    public function handle(ClinicOnboardingService $onboarding): int
    {
        $spec = $this->readSpec();

        if ($spec === null) {
            return self::FAILURE;
        }

        try {
            $spec = $onboarding->validate($spec);
        } catch (ValidationException $e) {
            $this->error('The description was refused; nothing was written:');

            foreach ($e->validator->errors()->all() as $message) {
                $this->line('  - '.$message);
            }

            return self::FAILURE;
        }

        $this->showPlan($spec, $onboarding);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Create this clinic on '.config('app.env').'?')) {
            $this->warn('Cancelled; nothing was written.');

            return self::FAILURE;
        }

        try {
            $result = $onboarding->onboard($spec);
        } catch (ValidationException $e) {
            $this->error('Refused; nothing was written: '.implode(' ', $e->validator->errors()->all()));

            return self::FAILURE;
        } catch (ApiException $e) {
            $this->error('Refused; nothing was written: '.$e->getMessage());

            return self::FAILURE;
        }

        $clinic = $result['clinic'];

        $this->newLine();
        $this->info("Clinic {$clinic->id} created: ".route('landing', ['slug' => $clinic->slug]));
        $this->warn('Passwords are shown once. Save them now.');
        $this->table(
            ['Account', 'Login email', 'Password', 'Doctor login'],
            array_map(fn (array $account) => [
                $account['name'],
                $account['email'],
                $account['password'],
                $account['main'] ? 'yes' : '',
            ], $result['accounts']),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSpec(): ?array
    {
        $file = (string) $this->argument('file');
        $json = $file === '-' ? stream_get_contents(STDIN) : (is_file($file) ? file_get_contents($file) : false);

        if ($json === false || trim($json) === '') {
            $this->error($file === '-' ? 'Nothing was read from stdin.' : "File not found: {$file}");

            return null;
        }

        $spec = json_decode($json, true);

        if (! is_array($spec)) {
            $this->error('The description is not valid JSON: '.json_last_error_msg());

            return null;
        }

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function showPlan(array $spec, ClinicOnboardingService $onboarding): void
    {
        $clinic = $spec['clinic'];
        $onOff = fn (string $key) => ($clinic[$key] ?? false) ? 'on' : 'off';

        $this->info('Environment: '.config('app.env'));
        $this->table(['Clinic', ''], [
            ['Name', $clinic['name']],
            ['Page', url($clinic['slug'])],
            ['Specialty', $clinic['specialty']],
            ['Phone', $clinic['phone']],
            ['Address', $clinic['address'] ?? '—'],
            ['City', $clinic['city'] ?? '—'],
            ['Self-booking / WhatsApp / Reports', $onOff('self_booking_enabled').' / '.$onOff('whatsapp_enabled').' / '.$onOff('reports_enabled')],
        ]);

        $this->table(['Doctor', ''], [
            ['Name', $spec['doctor']['name']],
            ['Title', $spec['doctor']['title'] ?? '—'],
            ['Sex', $spec['doctor']['sex'] ?? '—'],
        ]);

        $this->table(['Account', 'Login email', 'Doctor login'], array_map(fn (array $account) => [
            $account['name'],
            $account['email'],
            ($account['main'] ?? false) ? 'yes' : '',
        ], $spec['accounts']));

        $this->table(['Day', 'Hours'], array_map(function (DayOfWeek $day) use ($spec, $onboarding): array {
            $periods = $spec['schedule'][$onboarding->dayKey($day)] ?? [];

            return [
                $day->label(),
                $periods === [] ? 'closed' : implode(', ', array_map(fn (array $period) => $period[0].'–'.$period[1], $periods)),
            ];
        }, DayOfWeek::week()));
    }
}
