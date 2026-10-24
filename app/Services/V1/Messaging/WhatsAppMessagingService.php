<?php

namespace App\Services\V1\Messaging;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PatientResponse;
use App\Exceptions\ApiException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WhatsAppMessagingService
{
    public function __construct(
        private readonly TemplatePayloadResolver $payloads = new TemplatePayloadResolver,
    ) {}

    /** The template sent to a patient when their booking is taken. */
    public const CONFIRMATION_KEY = 'booking_confirmed';

    /** Sent once the visit is finished, inviting a review. */
    public const VISIT_COMPLETED_KEY = 'visit_completed';

    /**
     * The one broadcast that also does something: it cancels the day. With
     * WhatsApp off it still cancels, and only the message is dropped.
     */
    public const CANCELS_THE_DAY_KEY = 'day_cancelled';

    /** Sent to each patient an emergency delay moved, with their new time. */
    public const DELAYED_KEY = 'appointment_delayed';

    /** Sent on the visit day at the clinic's reminder time. */
    public const REMINDER_KEY = 'appointment_reminder_today';

    /** Quick-reply button payloads on the approved reminder template. */
    public const REPLY_CONFIRM = 'confirm_attendance';

    public const REPLY_CANCEL = 'cancel_booking';

    /** The free-text messages sent after a button, while the session is open. */
    public const REMINDER_CONFIRMED_KEY = 'appointment_reminder_confirmed';

    public const REMINDER_CANCELLED_KEY = 'appointment_reminder_cancelled';

    /**
     * What staff may send. Given a clinic with WhatsApp off, only the
     * cancellation — the rest would only be refused.
     *
     * @return Collection<int, MessageTemplate>
     */
    public function templates(?Clinic $clinic = null): Collection
    {
        return MessageTemplate::query()
            ->where('is_active', true)
            ->where('is_broadcast', true)
            ->when($clinic !== null && ! $clinic->sendsWhatsApp(), fn ($query) => $query->where('key', self::CANCELS_THE_DAY_KEY))
            ->orderBy('key')
            ->get();
    }

    /**
     * @param  list<int>|null  $bookingIds
     * @return array<string, mixed>
     */
    public function broadcast(Clinic $clinic, string $templateKey, ?Carbon $date = null, ?array $bookingIds = null): array
    {
        $date ??= $clinic->currentDay();
        $query = $clinic->bookings()
            ->with('patient')
            ->pending()
            ->orderBy('start_at');

        if ($bookingIds === null || $bookingIds === []) {
            $query->onDate($date->toDateString());
        } else {
            $query->whereKey($bookingIds);
        }

        return $this->sendForBookings($clinic, $templateKey, $query->get());
    }

    public function sendForBooking(Clinic $clinic, int $bookingId, string $templateKey): array
    {
        $booking = $clinic->bookings()
            ->with('patient')
            ->whereKey($bookingId)
            ->first();

        if ($booking === null) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_FOUND,
                __('booking.not_found'),
                http: 404,
            );
        }

        return $this->sendForBookings($clinic, $templateKey, collect([$booking]));
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, mixed>
     */
    private function sendForBookings(Clinic $clinic, string $templateKey, Collection $bookings): array
    {
        $template = $this->template($templateKey, broadcastOnly: true);
        $messages = [];
        $skipped = [];
        $cancelled = 0;
        $sending = $clinic->sendsWhatsApp();

        // Switched off by a super admin. A message on its own is refused
        // outright; the cancellation is the clinic's own business and goes
        // ahead, silently.
        if (! $sending && $template->key !== self::CANCELS_THE_DAY_KEY) {
            throw ApiException::make(
                ApiErrorCode::WHATSAPP_DISABLED,
                __('messages.whatsapp_disabled'),
                http: 409,
            );
        }

        if ($template->key === self::CANCELS_THE_DAY_KEY) {
            $cancelled = $this->cancelBookings($bookings);
        }

        foreach ($bookings as $booking) {
            if (! $sending) {
                $skipped[] = [
                    'booking_id' => $booking->id,
                    'patient_id' => $booking->patient?->id,
                    'reason' => 'whatsapp_disabled',
                ];

                continue;
            }

            $patient = $booking->patient;

            if ($patient === null || $patient->whatsapp_opt_in_at === null) {
                $skipped[] = [
                    'booking_id' => $booking->id,
                    'patient_id' => $patient?->id,
                    'reason' => 'whatsapp_not_opted_in',
                ];

                continue;
            }

            $message = $this->queue($clinic, $booking, $patient, $template);

            SendWhatsAppMessage::dispatch($message->id);
            $messages[] = $message->id;
        }

        return [
            'template_key' => $template->key,
            'sent_count' => count($messages),
            'skipped_count' => count($skipped),
            'cancelled_count' => $cancelled,
            'message_ids' => $messages,
            'skipped' => $skipped,
            'whatsapp_enabled' => $sending,
        ];
    }

    /**
     * @param  bool  $broadcastOnly  refuse per-booking templates, so a
     *                               confirmation can never be sent to a whole day
     */
    private function template(string $key, bool $broadcastOnly = false): MessageTemplate
    {
        $template = MessageTemplate::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->when($broadcastOnly, fn ($query) => $query->where('is_broadcast', true))
            ->first();

        if ($template === null) {
            throw ApiException::make(
                ApiErrorCode::RESOURCE_NOT_FOUND,
                __('messages.not_found'),
                details: ['template_key' => $key],
                http: 404,
            );
        }

        return $template;
    }

    /**
     * The confirmation a patient gets when their booking is taken, carrying
     * the link to their tracking page.
     *
     * Returns null when there is nothing to send — no patient, no opt-in, or
     * the clinic has not been given the template yet. A booking must never
     * fail because a message could not go out.
     */
    public function sendConfirmation(Clinic $clinic, Booking $booking): ?OutboundMessage
    {
        return $this->sendForBookingUsing($clinic, $booking, self::CONFIRMATION_KEY);
    }

    /**
     * The thank-you that carries the review link, sent when the visit is
     * marked done. Same guarantees as the confirmation: silent when there is
     * nobody to reach, and never able to cost the status change.
     */
    public function sendVisitCompleted(Clinic $clinic, Booking $booking): ?OutboundMessage
    {
        return $this->sendForBookingUsing($clinic, $booking, self::VISIT_COMPLETED_KEY);
    }

    /**
     * The reminder sent on the visit day at the clinic's configured time.
     * Same guarantees as the confirmation; deciding which bookings are due,
     * and that none gets two, is the command's job.
     */
    public function sendReminder(Clinic $clinic, Booking $booking): ?OutboundMessage
    {
        return $this->sendForBookingUsing($clinic, $booking, self::REMINDER_KEY);
    }

    /**
     * The free text after the patient taps a reminder button. Not a template:
     * the patient opened the conversation, so this travels inside WhatsApp's
     * 24-hour session window and carries no template cost.
     */
    public function sendReminderFollowUp(Clinic $clinic, Booking $booking, PatientResponse $response): ?OutboundMessage
    {
        if (! $clinic->sendsWhatsApp()) {
            return null;
        }

        $patient = $booking->patient;

        if ($patient === null) {
            return null;
        }

        $key = $response === PatientResponse::CONFIRMED
            ? self::REMINDER_CONFIRMED_KEY
            : self::REMINDER_CANCELLED_KEY;

        $body = $response === PatientResponse::CONFIRMED
            ? __('messages.reminder_follow_up.confirmed', ['link' => $booking->trackingUrl()])
            : __('messages.reminder_follow_up.cancelled', ['phone' => $this->clinicPhone($clinic)]);

        $message = OutboundMessage::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'booking_id' => $booking->id,
            'template_key' => $key,
            'type' => 'text',
            'rendered_body' => $body,
            'variables' => [],
            'status' => 'queued',
        ]);

        SendWhatsAppMessage::dispatch($message->id);

        return $message;
    }

    /**
     * The number printed in the cancellation follow-up — normalised the same
     * way as the number in the approved templates.
     */
    private function clinicPhone(Clinic $clinic): string
    {
        $phone = $clinic->phone === null
            ? null
            : PhoneNumber::tryParse($clinic->phone, $clinic->country_code)?->e164;

        return $phone ?? $clinic->phone ?? __('messages.fallback.phone');
    }

    /**
     * Tells each patient a delay moved their new time.
     *
     * Never refuses: the times have already moved, and a message that cannot
     * go out is reported per booking instead. `template_not_ready` while the
     * template is switched off.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return array{sent_count: int, skipped_count: int, message_ids: list<int>, skipped: list<array<string, mixed>>, whatsapp_enabled: bool}
     */
    public function sendDelayed(Clinic $clinic, Collection $bookings): array
    {
        $sending = $clinic->sendsWhatsApp();
        $template = MessageTemplate::query()
            ->where('key', self::DELAYED_KEY)
            ->where('is_active', true)
            ->first();
        $messages = [];
        $skipped = [];

        foreach ($bookings as $booking) {
            $patient = $booking->patient;

            $reason = match (true) {
                ! $sending => 'whatsapp_disabled',
                $template === null => 'template_not_ready',
                $patient === null || $patient->whatsapp_opt_in_at === null => 'whatsapp_not_opted_in',
                default => null,
            };

            if ($reason !== null) {
                $skipped[] = [
                    'booking_id' => $booking->id,
                    'patient_id' => $patient?->id,
                    'reason' => $reason,
                ];

                continue;
            }

            $message = $this->queue($clinic, $booking, $patient, $template);

            SendWhatsAppMessage::dispatch($message->id);
            $messages[] = $message->id;
        }

        return [
            'sent_count' => count($messages),
            'skipped_count' => count($skipped),
            'message_ids' => $messages,
            'skipped' => $skipped,
            'whatsapp_enabled' => $sending,
        ];
    }

    private function sendForBookingUsing(Clinic $clinic, Booking $booking, string $templateKey): ?OutboundMessage
    {
        // Off: nothing queued, and so nothing that could later read as sent.
        if (! $clinic->sendsWhatsApp()) {
            return null;
        }

        $patient = $booking->patient;

        if ($patient === null || $patient->whatsapp_opt_in_at === null) {
            return null;
        }

        try {
            $template = $this->template($templateKey);
        } catch (ApiException) {
            return null;
        }

        $message = $this->queue($clinic, $booking, $patient, $template);

        SendWhatsAppMessage::dispatch($message->id);

        return $message;
    }

    /**
     * Builds the row a queued message is, with everything the sender will need
     * frozen onto it: the parameters in the approved template's own order, and
     * the path its button points at.
     *
     * Frozen rather than recomputed at send time for the same reason
     * `rendered_body` always was — a message describes the booking as it stood
     * when it was queued.
     */
    private function queue(
        Clinic $clinic,
        Booking $booking,
        Patient $patient,
        MessageTemplate $template,
    ): OutboundMessage {
        $payload = $this->payloads->for($template, $booking, $clinic);

        return OutboundMessage::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'booking_id' => $booking->id,
            'template_key' => $template->key,
            'type' => 'template',
            'rendered_body' => $this->render($template, $payload->body),
            'variables' => $payload->body,
            'button_suffix' => $payload->buttonSuffix,
            'status' => 'queued',
        ]);
    }

    /**
     * @param  list<string>  $variables
     */
    private function render(MessageTemplate $template, array $variables): string
    {
        $placeholders = [];

        foreach ($variables as $index => $value) {
            $placeholders['{{'.($index + 1).'}}'] = $value;
        }

        return strtr($template->body_ar, $placeholders);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function cancelBookings(Collection $bookings): int
    {
        $count = 0;

        foreach ($bookings as $booking) {
            if (! in_array($booking->status, BookingStatus::pending(), true)) {
                continue;
            }

            $booking->update([
                'status' => BookingStatus::CANCELLED,
                'cancel_reason' => CancelReason::EMERGENCY,
                'cancelled_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }
}
