<?php

namespace App\Services\V1\Messaging;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PatientResponse;
use App\Events\ReminderResponseReceived;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Services\V1\Queue\BookingStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Handles a patient tapping Confirm or Cancel on the reminder.
 *
 * Meta sends these as `button` messages (or `interactive.button_reply`), and
 * only two things are trusted: that the callback was signed — the controller
 * has already checked that — and that the sender's number is the one the
 * reminder went to. The booking is then found through the message id Meta
 * quotes back, not through anything the caller can choose.
 *
 * Confirm only records the answer; the booking stays `booked`. Cancel goes
 * through the same service as a cancellation from the app, so status, slot
 * release, queue updates and notifications cannot drift apart.
 */
class WhatsAppReplyHandler
{
    /** Button payloads/titles we accept, in addition to a contains check. */
    private const CONFIRM_ALIASES = [
        'confirm', 'confirmed', 'confirm_attendance', 'confirm attendance',
        'تأكيد', 'تأكيد الحضور', 'اكد', 'أكد',
    ];

    private const CANCEL_ALIASES = [
        'cancel', 'cancelled', 'canceled', 'cancel_booking', 'cancel booking',
        'إلغاء', 'الغاء', 'إلغاء الحجز', 'الغاء الحجز', 'ألغى', 'الغي',
    ];

    public function __construct(
        private readonly BookingStatusService $statuses,
        private readonly WhatsAppMessagingService $messaging,
    ) {}

    /**
     * @param  array<string, mixed>  $message  one entry of `value.messages`
     */
    public function handle(array $message): ?Booking
    {
        $response = $this->responseFrom($message);

        if ($response === null) {
            return null;
        }

        $reminder = $this->reminderFor($message);

        if ($reminder === null || $reminder->booking === null) {
            return null;
        }

        $booking = $reminder->booking;

        // Replies are only meaningful while the visit is still merely booked.
        // Arrived, completed, cancelled by the clinic: nothing changes.
        if ($booking->status !== BookingStatus::BOOKED) {
            return null;
        }

        // A cancellation after the visit was due is ignored — the clinic's
        // list has moved on, and freeing a slot that is already past helps
        // nobody. A confirmation at that point is harmless and still recorded.
        if ($response === PatientResponse::CANCELLED && $this->visitHasStarted($booking)) {
            return null;
        }

        if ($response === PatientResponse::CANCELLED) {
            $this->statuses->cancel($booking, CancelReason::PATIENT_CANCELLED);
        }

        $booking->update([
            'patient_response' => $response,
            'patient_responded_at' => $this->now($booking),
        ]);

        $booking->refresh()->loadMissing(['patient', 'clinic']);

        // The free text is inside the session the patient just opened, so it
        // carries no template cost. A messaging failure must not undo the
        // reply the patient already gave.
        try {
            $this->messaging->sendReminderFollowUp($booking->clinic, $booking, $response);
        } catch (Throwable $e) {
            report($e);
        }

        ReminderResponseReceived::dispatch($booking);

        return $booking;
    }

    /**
     * Which button was tapped, if this message is one of the reminder's two.
     *
     * @param  array<string, mixed>  $message
     */
    private function responseFrom(array $message): ?PatientResponse
    {
        $values = [];

        if (($message['type'] ?? null) === 'button') {
            $values[] = $message['button']['payload'] ?? null;
            $values[] = $message['button']['text'] ?? null;
        }

        if (($message['type'] ?? null) === 'interactive'
            && ($message['interactive']['type'] ?? null) === 'button_reply') {
            $values[] = $message['interactive']['button_reply']['id'] ?? null;
            $values[] = $message['interactive']['button_reply']['title'] ?? null;
        }

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normal = mb_strtolower(trim($value));

            if (in_array($normal, self::CONFIRM_ALIASES, true)
                || str_contains($normal, 'تأكيد')
                || str_contains($normal, 'confirm')) {
                return PatientResponse::CONFIRMED;
            }

            if (in_array($normal, self::CANCEL_ALIASES, true)
                || str_contains($normal, 'إلغاء')
                || str_contains($normal, 'الغاء')
                || str_contains($normal, 'cancel')) {
                return PatientResponse::CANCELLED;
            }
        }

        return null;
    }

    /**
     * The reminder this reply belongs to, verified against the sender's
     * number. Meta quotes the original message id back in `context.id`; with
     * that we need no guesswork. Without it, the newest reminder to that same
     * number is the only defensible fallback.
     *
     * @param  array<string, mixed>  $message
     */
    private function reminderFor(array $message): ?OutboundMessage
    {
        $from = $this->digits($message['from'] ?? null);

        if ($from === '') {
            return null;
        }

        $contextId = $message['context']['id'] ?? null;

        if (is_string($contextId) && $contextId !== '') {
            $reminder = $this->reminderQuery()
                ->where('provider_message_id', $contextId)
                ->first();

            // A known message id from a different number is an attempt to
            // answer somebody else's reminder: refuse it outright.
            return $reminder !== null && $this->sameNumber($reminder, $from) ? $reminder : null;
        }

        $reminder = $this->reminderQuery()
            ->whereHas('patient', fn ($query) => $query
                ->where('phone', '+'.$from)
                ->orWhere('phone', $from))
            ->latest('id')
            ->first();

        return $reminder !== null && $this->sameNumber($reminder, $from) ? $reminder : null;
    }

    /**
     * @return Builder<OutboundMessage>
     */
    private function reminderQuery()
    {
        return OutboundMessage::query()
            ->where('template_key', WhatsAppMessagingService::REMINDER_KEY)
            ->whereNotNull('booking_id')
            ->with(['patient', 'booking.clinic', 'booking.patient']);
    }

    private function sameNumber(OutboundMessage $reminder, string $from): bool
    {
        return $this->digits($reminder->patient?->phone) === $from;
    }

    private function digits(mixed $phone): string
    {
        return is_string($phone) ? (preg_replace('/[^0-9]/', '', $phone) ?? '') : '';
    }

    private function visitHasStarted(Booking $booking): bool
    {
        if ($booking->start_at === null) {
            return false;
        }

        // Both sides are the clinic's wall clock.
        $start = Carbon::parse($booking->start_at->format('Y-m-d H:i:s'), $booking->clinic->timezone);

        return Carbon::now($booking->clinic->timezone)->gt($start);
    }

    private function now(Booking $booking): Carbon
    {
        return Carbon::now($booking->clinic?->timezone ?? config('app.timezone'));
    }
}
