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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WhatsAppMessagingService
{
    /**
     * @return Collection<int, MessageTemplate>
     */
    public function templates(): Collection
    {
        return MessageTemplate::query()
            ->where('is_active', true)
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
        $template = $this->template($templateKey);
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

            $message = OutboundMessage::create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'booking_id' => $booking->id,
                'template_key' => $template->key,
                'rendered_body' => $this->render($template, $patient->name, $clinic->name),
                'status' => 'queued',
            ]);

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

    private function template(string $key): MessageTemplate
    {
        $template = MessageTemplate::query()
            ->where('key', $key)
            ->where('is_active', true)
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

    private function render(MessageTemplate $template, string $patientName, string $clinicName): string
    {
        return str_replace(['{{1}}', '{{2}}'], [$patientName, $clinicName], $template->body_ar);
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
