<?php

namespace App\Services\V1\Reports;

use App\Jobs\SendClinicReport;
use App\Models\Clinic;
use App\Models\ReportDelivery;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Who gets a clinic's report on WhatsApp, and making sure they get it once.
 *
 * Recipients are the clinic's active accounts flagged `can_access_reports`
 * that have a phone. The patient WhatsApp switch has no say here: this goes
 * to the doctor, not to patients.
 */
class ReportDeliveryService
{
    public function __construct(private readonly ReportCalendar $calendar) {}

    /**
     * Sends one period's report to each recipient who has not had it yet.
     *
     * `$force` resends to people who already had it — the panel's explicit
     * "send again". Without it, a period that went out is never repeated, so
     * the morning job and a "send now" can both run safely.
     *
     * @return array<string, string> recipient email => queued | already_sent
     */
    public function deliver(Clinic $clinic, string $type, string $value, bool $force = false): array
    {
        $period = $this->calendar->resolve($clinic, $type, $value);

        if ($period === null) {
            throw new InvalidArgumentException("[$type $value] is not a report period this clinic can open.");
        }

        $outcome = [];

        foreach ($this->recipients($clinic) as $user) {
            $delivery = ReportDelivery::firstOrNew([
                'user_id' => $user->id,
                'period_type' => $type,
                'period_start' => $period->from->toDateString(),
            ], ['clinic_id' => $clinic->id]);

            if ($delivery->exists && $delivery->status === 'sent' && ! $force) {
                $outcome[$user->email] = 'already_sent';

                continue;
            }

            $delivery->fill(['clinic_id' => $clinic->id, 'status' => 'queued', 'error' => null])->save();
            $outcome[$user->email] = 'queued';

            SendClinicReport::dispatch($delivery->id, $value);
        }

        return $outcome;
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(Clinic $clinic): Collection
    {
        return $clinic->staff()
            ->where('users.is_active', true)
            ->where('users.can_access_reports', true)
            ->whereNotNull('users.phone')
            ->where('users.phone', '!=', '')
            ->orderBy('users.id')
            ->get();
    }
}
