<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Support\Carbon;

/**
 * The app's original reminder, timed by the clinic's slot position.
 *
 * Each clinic picks how many slots ahead the reminder goes (Settings →
 * Timing). A booking is reminded `reminderPosition × its own slot length`
 * minutes before the visit: at 6 with 30-minute visits, three hours ahead;
 * at 1, one slot ahead. The slot length is the booking's own, so a 15-minute
 * follow-up is not timed like a 45-minute procedure.
 *
 * A booking is reminded at most once: a message already queued for it under
 * the hourly template, whatever became of it, rules it out. So a run that
 * overlaps another, or follows a gap, never sends twice — and a gap is caught
 * up, as long as the appointment is still ahead.
 *
 * A booking made inside its lead is left alone: its confirmation went out a
 * moment ago and says everything the reminder would.
 *
 * Only bookings still `booked`: a patient who has arrived needs no reminder,
 * and a cancelled one must not get one. A closed day has nobody to remind.
 *
 * Times are stored as the clinic's wall clock, so every comparison here is
 * made in the clinic's own timezone.
 */
class SendBookingRemindersCommand extends ClinicReminderCommand
{
    protected $signature = 'clinic:send-reminders {--clinic= : Only this clinic id}';

    protected $description = 'Send the WhatsApp reminder to patients whose visit is a few slots away';

    protected function reminderMethod(): string
    {
        return 'sendReminder';
    }

    protected function due(Clinic $clinic): iterable
    {
        // Times are stored as the clinic's wall clock.
        $now = Carbon::now($clinic->timezone);
        $slotsAhead = $clinic->reminderPosition();

        // The furthest a reminder can now be due: the longest visit booked
        // ahead times the clinic's position. Everything later costs no query.
        $longestVisit = (int) $clinic->bookings()
            ->where('status', BookingStatus::BOOKED)
            ->where('start_at', '>', $now->format('Y-m-d H:i:s'))
            ->max('duration_minutes');

        if ($longestVisit < 1) {
            return [];
        }

        $bookings = $clinic->bookings()
            ->with('patient')
            ->where('status', BookingStatus::BOOKED)
            ->where('start_at', '>', $now->format('Y-m-d H:i:s'))
            ->where('start_at', '<=', $now->copy()->addMinutes($slotsAhead * $longestVisit)->format('Y-m-d H:i:s'))
            ->whereNotExists(fn ($query) => $query->from((new OutboundMessage)->getTable())
                ->whereColumn('booking_id', 'bookings.id')
                ->where('template_key', WhatsAppMessagingService::REMINDER_KEY))
            ->get();

        if ($bookings->isEmpty()) {
            return [];
        }

        // A long lead can put the reminder before midnight for a visit just
        // after it, so the holiday that matters is the visit's own day.
        $holidays = $bookings
            ->map(fn (Booking $booking): string => $booking->visit_date->toDateString())
            ->unique()
            ->filter(fn (string $date): bool => $clinic->holidays()->whereDate('date', $date)->exists());

        return $bookings
            ->reject(fn (Booking $booking): bool => $holidays->contains($booking->visit_date->toDateString()))
            ->filter(fn (Booking $booking): bool => $this->isDue($clinic, $booking, $now))
            ->values();
    }

    /**
     * Whether this booking's reminder is due now.
     */
    private function isDue(Clinic $clinic, Booking $booking, Carbon $now): bool
    {
        if ($booking->status !== BookingStatus::BOOKED || $booking->start_at === null) {
            return false;
        }

        $startAt = Carbon::parse($booking->start_at->format('Y-m-d H:i:s'), $clinic->timezone);

        if ($startAt->lte($now)) {
            return false;
        }

        $remindAt = $startAt->copy()->subMinutes(
            $clinic->reminderPosition() * max(1, (int) $booking->duration_minutes),
        );

        if ($now->lt($remindAt)) {
            return false; // not due yet
        }

        // created_at is stored in the application's clock, which is not
        // necessarily the clinic's. Compare it in the clinic's.
        $createdAt = $booking->created_at?->copy()->setTimezone($clinic->timezone);

        return $createdAt !== null && $createdAt->lte($remindAt);
    }
}
