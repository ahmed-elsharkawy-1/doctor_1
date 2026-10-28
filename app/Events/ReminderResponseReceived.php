<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A patient answered the day's WhatsApp reminder.
 *
 * The card already carries the reminder and response badges, but a badge only
 * helps somebody looking at the screen. This is what reaches the secretary
 * when she is not — most importantly for a cancellation, where the freed slot
 * is worth filling today.
 *
 * The payload is deliberately small: the queue re-reads the booking itself,
 * scoped to her clinic and through the same authorisation as every other read.
 */
class ReminderResponseReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Booking $booking) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(SelfBookingReceived::channelFor($this->booking->clinic_id));
    }

    public function broadcastAs(): string
    {
        return 'ReminderResponseReceived';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $booking = $this->booking->loadMissing('patient');

        return [
            'booking_id' => $booking->id,
            'patient_name' => $booking->patient?->name,
            'patient_response' => $booking->patient_response?->value,
            'status' => $booking->status->value,
            'date' => $booking->visit_date->toDateString(),
        ];
    }
}
