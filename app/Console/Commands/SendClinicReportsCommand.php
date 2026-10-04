<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\V1\Reports\ReportCalendar;
use App\Services\V1\Reports\ReportDeliveryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The doctors' morning report, for every clinic with reports switched on.
 *
 * Safe to run twice: a period that already went out is never resent. One
 * clinic failing does not stop the others — its delivery is marked failed and
 * can be sent again from the admin panel.
 */
class SendClinicReportsCommand extends Command
{
    protected $signature = 'clinic:send-reports {--clinic= : Only this clinic id}';

    protected $description = "Send each enabled clinic's report to its doctor's WhatsApp";

    public function handle(ReportDeliveryService $deliveries, ReportCalendar $calendar): int
    {
        $clinics = Clinic::query()
            ->where('is_active', true)
            ->where('reports_enabled', true)
            ->when($this->option('clinic'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        foreach ($clinics as $clinic) {
            [$type, $value] = $calendar->morning($clinic);

            try {
                $outcome = $deliveries->deliver($clinic, $type, $value);
                $this->info("{$clinic->name}: {$type} {$value} — ".json_encode($outcome, JSON_UNESCAPED_UNICODE));
            } catch (Throwable $e) {
                report($e);
                $this->warn("{$clinic->name}: {$type} {$value} failed — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
