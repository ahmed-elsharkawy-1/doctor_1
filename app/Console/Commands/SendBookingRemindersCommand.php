<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Support\Carbon;

/**
 * The app's original reminder: an hour before each visit.
 *
 * A booking is reminded at most once: a message already queued for it under
 * the hourly template, whatever became of it, rules it out. So a run that
 * overlaps another, or follows a gap, never sends twice — and a gap is caught
 * up, as long as the appointment is still ahead.
 *
 * A booking made inside that hour is left alone: its confirmation went out a
 * moment ago and says everything the reminder would. Emergencies have no
 * `start_at` on this branch, so the window excludes them on its own.
 *
 * Only bookings still `booked`: a patient who has arrived needs no reminder,
 * and a cancelled one must not get one.
 *
 * Times are stored as the clinic's wall clock, so every comparison here is
 * made in the clinic's own timezone.
 */
class SendBookingRemindersCommand extends ClinicReminderCommand
{
    protected $signature = 'clinic:send-reminders {--clinic= : Only this clinic id}';

    protected $description = 'Send the WhatsApp reminder to patients whose appointment is coming up';

    protected function reminderMethod(): string
    {
        return 'sendReminder';
    }

    protected function due(Clinic $clinic): iterable
    {
        // Times are stored as the clinic's wall clock.
        $now = Carbon::now($clinic->timezone);
        $today = $now->toDateString();

        // A closed day has nobody to remind; bookings should not exist on it,
        // but a holiday can be added after they were taken.
        if ($clinic->holidays()->whereDate('date', $today)->exists()) {
            return [];
        }

        return $clinic->bookings()
            ->with('patient')
            ->where('status', BookingStatus::BOOKED)
            ->where('start_at', '>', $now->format('Y-m-d H:i:s'))
            ->where('start_at', '<=', $now->copy()->addMinutes((int) config('clinic.messaging.reminder_lead_minutes'))->format('Y-m-d H:i:s'))
            ->whereNotExists(fn ($query) => $query->from((new OutboundMessage)->getTable())
                ->whereColumn('booking_id', 'bookings.id')
                ->where('template_key', WhatsAppMessagingService::REMINDER_KEY))
            ->get()
            ->filter(function (Booking $booking) use ($clinic): bool {
                $remindAt = Carbon::parse($booking->start_at->format('Y-m-d H:i:s'), $clinic->timezone)
                    ->subMinutes((int) config('clinic.messaging.reminder_lead_minutes'));

                // created_at is stored in the application's clock, which is
                // not necessarily the clinic's. Compare it in the clinic's.
                $createdAt = $booking->created_at?->copy()->setTimezone($clinic->timezone);

                return $createdAt !== null && $createdAt->lte($remindAt);
            });
    }
}
