<?php

namespace App\Console\Commands;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Support\Carbon;

/**
 * Sends the day's one reminder at each clinic's own reminder time.
 *
 * A booking is reminded at most once: any row already queued for it under the
 * visit-day template, whatever became of it, rules it out. So a run that
 * overlaps another, or follows a gap, never sends twice.
 *
 * Only for a clinic whose super admin has switched it on (off by default).
 *
 * Eligible is deliberately narrow: the visit is today, still `booked`, not an
 * emergency, was made at least the clinic's minimum hours before the reminder
 * time (12 by default: at 11:00, by 23:00 the night before), has not already
 * started, and the clinic is not on holiday. A morning appointment earlier than the
 * reminder time is skipped — the reminder would arrive after the visit — and
 * so is one already inside the clinic's WhatsApp cancel cutoff: that
 * reminder's Cancel button could only be refused.
 *
 * Times are stored as the clinic's wall clock, so every comparison here is
 * made in the clinic's own timezone.
 */
class SendDailyRemindersCommand extends ClinicReminderCommand
{
    protected $signature = 'clinic:send-daily-reminders {--clinic= : Only this clinic id}';

    protected $description = 'Send the daily WhatsApp reminder to every patient booked today';

    protected function reminderMethod(): string
    {
        return 'sendDailyReminder';
    }

    protected function enabledFor(Clinic $clinic): bool
    {
        return $clinic->sendsDailyReminder();
    }

    protected function due(Clinic $clinic): iterable
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

        // A booking made later than this has only just had its confirmation;
        // the morning reminder would be a second message about the same visit.
        $madeBy = $reminderAt->copy()->subHours($clinic->dailyReminderMinHours());

        // A visit already inside the clinic's cancel cutoff would get a Cancel
        // button that can only be refused, so it is not reminded at all. With
        // no cutoff this is just `now`, which stops a late run reminding a
        // patient whose visit has already begun.
        $cancellableFrom = $now->copy()->addMinutes($clinic->whatsAppCancelCutoffMinutes());

        // The times are wall-clock values for the clinic, so a string compare
        // against the same wall clock is exact.
        return $clinic->bookings()
            ->with('patient')
            ->whereDate('visit_date', $today)
            ->where('status', BookingStatus::BOOKED)
            ->where('booking_kind', BookingKind::NORMAL)
            ->where('start_at', '>', $reminderAt->format('Y-m-d H:i:s'))
            ->where('start_at', '>', $cancellableFrom->format('Y-m-d H:i:s'))
            ->whereNotExists(fn ($query) => $query->from((new OutboundMessage)->getTable())
                ->whereColumn('booking_id', 'bookings.id')
                ->where('template_key', WhatsAppMessagingService::DAILY_REMINDER_KEY))
            ->get()
            ->filter(function (Booking $booking) use ($clinic, $madeBy): bool {
                // created_at is stored in the application's clock, which is
                // not necessarily the clinic's. Compare it in the clinic's.
                $createdAt = $booking->created_at?->copy()->setTimezone($clinic->timezone);

                return $createdAt !== null && $createdAt->lte($madeBy);
            });
    }
}
