<?php

namespace App\Console\Commands;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Sends the day's one reminder at each clinic's own reminder time.
 *
 * A booking is reminded at most once: any row already queued for it under the
 * reminder template, whatever became of it, rules it out. So a run that
 * overlaps another, or follows a gap, never sends twice.
 *
 * Eligible is deliberately narrow: the visit is today, still `booked`, not an
 * emergency, was made before the reminder time, has not already started, and
 * the clinic is not on holiday. A morning appointment earlier than the
 * reminder time is skipped — the reminder would arrive after the visit.
 *
 * Times are stored as the clinic's wall clock, so every comparison here is
 * made in the clinic's own timezone.
 */
class SendBookingRemindersCommand extends Command
{
    protected $signature = 'clinic:send-reminders {--clinic= : Only this clinic id}';

    protected $description = 'Send the daily WhatsApp reminder to every patient booked today';

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
                    if ($messaging->sendReminder($clinic, $booking) !== null) {
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

    /**
     * @return iterable<int, Booking>
     */
    private function due(Clinic $clinic): iterable
    {
        $now = Carbon::now($clinic->timezone);
        $today = $now->toDateString();
        $reminderAt = Carbon::parse($today.' '.$clinic->reminderTime(), $clinic->timezone);

        // Not yet the clinic's reminder time. The command runs every minute,
        // so this is the ordinary case for almost the whole day.
        if ($now->lt($reminderAt)) {
            return [];
        }

        // A closed day has nobody to remind; bookings should not exist on it,
        // but a holiday can be added after they were taken.
        if ($clinic->holidays()->whereDate('date', $today)->exists()) {
            return [];
        }

        // The times are wall-clock values for the clinic, so a string compare
        // against the same wall clock is exact. `start_at > now` also stops a
        // late run reminding a patient whose visit has already begun.
        return $clinic->bookings()
            ->with('patient')
            ->whereDate('visit_date', $today)
            ->where('status', BookingStatus::BOOKED)
            ->where('booking_kind', BookingKind::NORMAL)
            ->where('start_at', '>', $reminderAt->format('Y-m-d H:i:s'))
            ->where('start_at', '>', $now->format('Y-m-d H:i:s'))
            ->whereNotExists(fn ($query) => $query->from((new OutboundMessage)->getTable())
                ->whereColumn('booking_id', 'bookings.id')
                ->where('template_key', WhatsAppMessagingService::REMINDER_KEY))
            ->get()
            ->filter(function (Booking $booking) use ($clinic, $reminderAt): bool {
                // created_at is stored in the application's clock, which is
                // not necessarily the clinic's. Compare it in the clinic's.
                $createdAt = $booking->created_at?->copy()->setTimezone($clinic->timezone);

                return $createdAt !== null && $createdAt->lte($reminderAt);
            });
    }
}
