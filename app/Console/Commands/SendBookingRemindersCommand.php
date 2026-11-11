<?php

namespace App\Console\Commands;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Queue\QueuePositionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The turn alert: the message that gets a patient moving to the clinic.
 *
 * A patient is alerted on the day of the visit, once, when both are true:
 *
 *  - no more than the clinic's position are still ahead of them, counted
 *    exactly as their tracking page counts (QueuePositionService);
 *  - the doctor has started, or their booked time is a few minutes away
 *    (clinic.turn_alert.first_patients_lead_minutes).
 *
 * The clock alone cannot time this: doctors run late, and a patient told to
 * go now would then wait an hour. The line says how close the turn really is.
 * But the first patients of the day are close from the start, so without the
 * second condition they would be alerted first thing in the morning; and if
 * it were "the doctor has started" alone, they would hear only once the
 * doctor is already seeing them. Their booked time moves them instead, a
 * little early, so that "go now" does not arrive when they should be there.
 *
 * A booking is alerted at most once: a message already queued for it under
 * this template, whatever became of it, rules it out. So a run that overlaps
 * another, or follows a gap, never sends twice — and a gap is caught up.
 *
 * Only normal bookings still `booked`: a patient who has arrived needs no
 * alert, a cancelled one must not get one, and the clinic deals with an
 * emergency directly. A booking made when the turn is already close is
 * alerted straight away, because its confirmation does not say to go now.
 * A closed day has nobody to alert.
 *
 * Times are stored as the clinic's wall clock, so every comparison here is
 * made in the clinic's own timezone.
 */
class SendBookingRemindersCommand extends ClinicReminderCommand
{
    protected $signature = 'clinic:send-reminders {--clinic= : Only this clinic id}';

    protected $description = 'Send the WhatsApp turn alert to patients whose turn is getting close';

    public function __construct(private readonly QueuePositionService $positions)
    {
        parent::__construct();
    }

    protected function reminderMethod(): string
    {
        return 'sendReminder';
    }

    protected function enabledFor(Clinic $clinic): bool
    {
        return $clinic->sendsPreVisitReminder();
    }

    protected function due(Clinic $clinic): iterable
    {
        $day = $clinic->currentDay()->toDateString();

        if ($clinic->holidays()->whereDate('date', $day)->exists()) {
            return [];
        }

        $queue = $this->positions->today($clinic);
        $waiting = $this->notYetAlerted($queue);

        if ($waiting->isEmpty()) {
            return [];
        }

        $doctorStarted = $this->doctorStarted($clinic, $day);
        $now = Carbon::now($clinic->timezone);

        return $waiting
            ->filter(fn (Booking $booking): bool => $this->isDue($clinic, $queue, $booking, $doctorStarted, $now))
            ->values();
    }

    /**
     * Whether the doctor has begun the day: someone has gone in, finished or
     * not — counted only from shortly before the day opens. A visit marked in
     * the small hours, long before the session, says nothing about it.
     */
    private function doctorStarted(Clinic $clinic, string $day): bool
    {
        $calls = $clinic->bookings()->onDate($day)->whereNotNull('called_in_at');

        $date = Carbon::parse($day, $clinic->timezone);
        $schedule = $clinic->scheduleFor(DayOfWeek::fromDate($date));
        $first = $schedule?->is_open ? $schedule->periods->first() : null;

        if ($first !== null) {
            [$opens] = $first->boundsOn($date, $clinic->timezone);
            $from = $opens->copy()->subMinutes((int) config('clinic.turn_alert.started_window_minutes'));
            $calls->where('called_in_at', '>=', $from->format('Y-m-d H:i:s'));
        }

        return $calls->exists();
    }

    /**
     * @param  Collection<int, Booking>  $queue
     * @return Collection<int, Booking>
     */
    private function notYetAlerted(Collection $queue): Collection
    {
        $waiting = $queue->filter(fn (Booking $booking): bool => $booking->status === BookingStatus::BOOKED
            && $booking->booking_kind === BookingKind::NORMAL
            && $booking->start_at !== null);

        $alerted = OutboundMessage::query()
            ->whereIn('booking_id', $waiting->pluck('id'))
            ->where('template_key', WhatsAppMessagingService::REMINDER_KEY)
            ->pluck('booking_id')
            ->all();

        return $waiting->reject(fn (Booking $booking): bool => in_array($booking->id, $alerted, true));
    }

    /**
     * Whether this booking's alert is due now.
     *
     * @param  Collection<int, Booking>  $queue
     */
    private function isDue(Clinic $clinic, Collection $queue, Booking $booking, bool $doctorStarted, Carbon $now): bool
    {
        $position = $this->positions->within($clinic, $queue, $booking);

        if ($position === null || $position->ahead > $clinic->reminderPosition()) {
            return false;
        }

        if ($doctorStarted) {
            return true;
        }

        $alertFrom = Carbon::parse($booking->start_at->format('Y-m-d H:i:s'), $clinic->timezone)
            ->subMinutes((int) config('clinic.turn_alert.first_patients_lead_minutes'));

        return $now->gte($alertFrom);
    }
}
