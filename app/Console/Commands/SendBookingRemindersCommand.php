<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reminds each patient of their appointment an hour before it.
 *
 * A booking is reminded at most once: a message already queued for it under
 * the reminder template, whatever became of it, rules it out. So a run that
 * overlaps another, or follows a gap, never sends twice — and a gap is caught
 * up, as long as the appointment is still ahead.
 *
 * A booking made inside that hour is left alone. Its confirmation went out a
 * moment ago and says everything the reminder would.
 *
 * Only bookings still `booked`: a patient who has arrived needs no reminder,
 * and a cancelled one must not get one. Emergencies have no time to remind of.
 */
class SendBookingRemindersCommand extends Command
{
    protected $signature = 'clinic:send-reminders {--clinic= : Only this clinic id}';

    protected $description = 'Send the WhatsApp reminder to patients whose appointment is coming up';

    public function handle(WhatsAppMessagingService $messaging): int
    {
        $lead = (int) config('clinic.messaging.reminder_lead_minutes');

        $clinics = Clinic::query()
            ->where('is_active', true)
            ->when($this->option('clinic'), fn ($query, $id) => $query->whereKey($id))
            ->get()
            ->filter(fn (Clinic $clinic): bool => $clinic->sendsWhatsApp());

        $sent = 0;

        foreach ($clinics as $clinic) {
            foreach ($this->due($clinic, $lead) as $booking) {
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
    private function due(Clinic $clinic, int $lead): iterable
    {
        // Times are stored as the clinic's wall clock.
        $now = Carbon::now($clinic->timezone);

        return $clinic->bookings()
            ->with('patient')
            ->where('status', BookingStatus::BOOKED)
            ->where('start_at', '>', $now->format('Y-m-d H:i:s'))
            ->where('start_at', '<=', $now->copy()->addMinutes($lead)->format('Y-m-d H:i:s'))
            ->whereNotExists(fn ($query) => $query->from((new OutboundMessage)->getTable())
                ->whereColumn('booking_id', 'bookings.id')
                ->where('template_key', WhatsAppMessagingService::REMINDER_KEY))
            ->get()
            ->filter(function (Booking $booking) use ($clinic, $lead): bool {
                $remindAt = Carbon::parse($booking->start_at->format('Y-m-d H:i:s'), $clinic->timezone)
                    ->subMinutes($lead);

                return $booking->created_at->lte($remindAt);
            });
    }
}
