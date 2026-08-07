<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Clinic;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Closes out days that have finished — SPEC §5.4.
 *
 * Without this, a patient booked at 09:00 who never turned up is still
 * "not finished" at 6pm, keeps her slot blocked, and keeps appearing in the
 * postpone list. The secretary should not have to tidy up by hand.
 *
 * Runs hourly and is idempotent: each clinic is closed out only once its own
 * local day has rolled over, which is what makes this correct for clinics in
 * different timezones.
 */
class CloseClinicDayCommand extends Command
{
    protected $signature = 'clinic:close-day
                            {--clinic= : Limit to one clinic id}';

    protected $description = 'Cancel leftover bookings from days that have already ended';

    public function handle(): int
    {
        $clinics = Clinic::query()
            ->when($this->option('clinic'), fn ($query, $id) => $query->whereKey($id))
            ->where('is_active', true)
            ->get();

        $closed = 0;

        foreach ($clinics as $clinic) {
            $closed += $this->closeFor($clinic);
        }

        $this->info("Closed {$closed} leftover booking(s) across {$clinics->count()} clinic(s).");

        return self::SUCCESS;
    }

    private function closeFor(Clinic $clinic): int
    {
        $today = Carbon::now($clinic->timezone)->startOfDay()->toDateString();
        $now = Carbon::now($clinic->timezone);

        // Never touched the clinic: she never turned up.
        $noShows = $clinic->bookings()
            ->whereDate('visit_date', '<', $today)
            ->where('status', BookingStatus::BOOKED)
            ->update([
                'status' => BookingStatus::CANCELLED,
                'cancel_reason' => CancelReason::NO_SHOW,
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);

        // Arrived, or went in, but the visit was never completed — a data-entry
        // lapse rather than a no-show. Kept out of revenue either way.
        $incomplete = $clinic->bookings()
            ->whereDate('visit_date', '<', $today)
            ->whereIn('status', [BookingStatus::ARRIVED, BookingStatus::WITH_DOCTOR])
            ->update([
                'status' => BookingStatus::CANCELLED,
                'cancel_reason' => CancelReason::INCOMPLETE,
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);

        return $noShows + $incomplete;
    }
}
