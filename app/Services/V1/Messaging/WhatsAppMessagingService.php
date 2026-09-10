<?php

namespace App\Services\V1\Messaging;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\ApiException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Patient;
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
     * @return Collection<int, MessageTemplate>
     */
    public function templates(): Collection
    {
        return MessageTemplate::query()
            ->where('is_active', true)
            ->where('is_broadcast', true)
            ->orderBy('key')
            ->get();
    }

    /**
     * @param  list<int>|null  $bookingIds
     * @return array<string, mixed>
     */
    public function broadcast(Clinic $clinic, string $templateKey, ?Carbon $date = null, ?array $bookingIds = null): array
    {
        $date ??= Carbon::now($clinic->timezone)->startOfDay();
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

        if ($template->key === 'day_cancelled') {
            $cancelled = $this->cancelBookings($bookings);
        }

        foreach ($bookings as $booking) {
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

    private function sendForBookingUsing(Clinic $clinic, Booking $booking, string $templateKey): ?OutboundMessage
    {
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
