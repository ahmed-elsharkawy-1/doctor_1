<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Clinic;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * What the two reminder commands share: the clinics they sweep, and the one
 * promise each booking is held to — a message queued for it under the
 * reminder template rules a second send out, whatever became of the first,
 * so overlapping or missed runs never send twice.
 *
 * The reminders themselves are separate commands, so each can be scheduled
 * (or run by hand) without the other:
 *
 *   clinic:send-reminders        an hour before the visit — the app's
 *                                original reminder, kept from develop
 *   clinic:send-daily-reminders  on the visit day, at the clinic's own time
 *
 * A command that finds nobody due is a few queries per clinic; both run
 * every minute and gate themselves on the clock.
 */
abstract class ClinicReminderCommand extends Command
{
    /**
     * The WhatsAppMessagingService method this command queues through —
     * sendReminder or sendDailyReminder, which is also what fixes the
     * template key its deduplication reads.
     */
    abstract protected function reminderMethod(): string;

    /**
     * @return iterable<int, Booking>
     */
    abstract protected function due(Clinic $clinic): iterable;

    public function handle(WhatsAppMessagingService $messaging): int
    {
        $clinics = Clinic::query()
            ->where('is_active', true)
            ->when($this->option('clinic'), fn ($query, $id) => $query->whereKey($id))
            ->get()
            ->filter(fn (Clinic $clinic): bool => $clinic->sendsWhatsApp());

        $sent = 0;

        foreach ($clinics as $clinic) {
            foreach ($this->due($clinic) as $booking) {
                try {
                    if ($messaging->{$this->reminderMethod()}($clinic, $booking) !== null) {
                        $sent++;
                    }
                } catch (Throwable $e) {
                    report($e);
                    $this->warn("Booking {$booking->id}: {$e->getMessage()}");
                }
            }
        }

        if ($sent > 0) {
            $this->info("Queued {$sent} reminder(s).");
        }

        return self::SUCCESS;
    }
}
