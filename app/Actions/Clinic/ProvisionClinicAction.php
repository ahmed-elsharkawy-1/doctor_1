<?php

namespace App\Actions\Clinic;

use App\Enums\DayOfWeek;
use App\Models\Clinic;
use Illuminate\Support\Facades\DB;

/**
 * Gives a freshly created clinic everything it needs to be usable:
 * its specialty's default visit types, and a full seven-day week.
 *
 * Idempotent — running it again on a provisioned clinic changes nothing.
 */
class ProvisionClinicAction
{
    public function execute(Clinic $clinic): Clinic
    {
        return DB::transaction(function () use ($clinic) {
            $this->seedVisitTypes($clinic);
            $this->seedWeek($clinic);

            return $clinic->refresh();
        });
    }

    /**
     * Copies the specialty's default visit types. Prices start at zero — the
     * owner sets them before booking, since price is snapshotted per booking.
     */
    private function seedVisitTypes(Clinic $clinic): void
    {
        if ($clinic->visitTypes()->exists()) {
            return;
        }

        $defaults = $clinic->specialty?->defaultVisitTypes ?? collect();

        foreach ($defaults as $default) {
            $clinic->visitTypes()->create([
                'name' => $default->name_ar,
                'duration_minutes' => $default->duration_minutes,
                'price' => 0,
                'is_active' => true,
                'sort_order' => $default->sort_order,
            ]);
        }
    }

    /**
     * Creates all seven days, closed. The owner opens the ones they work and
     * adds periods — there is no implicit "open by default" day.
     */
    private function seedWeek(Clinic $clinic): void
    {
        $existing = $clinic->schedules()->pluck('day_of_week')->all();

        foreach (DayOfWeek::week() as $day) {
            if (in_array($day, $existing, true) || in_array($day->value, $existing, true)) {
                continue;
            }

            $clinic->schedules()->create([
                'day_of_week' => $day,
                'is_open' => false,
            ]);
        }
    }
}
