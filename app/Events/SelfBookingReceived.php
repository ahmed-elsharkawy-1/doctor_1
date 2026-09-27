<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A patient booked themselves, and nobody at the clinic was watching.
 *
 * This is the notification half of self-booking. The queue card carries a
 * badge saying where a booking came from, but a badge only works on somebody
 * already looking at the screen — this is what reaches the secretary when she
 * is not.
 *
 * Private, because unlike SlotsChanged it names a patient. Authorisation is
 * in routes/channels.php and comes down to one question: does this user work
 * at this clinic.
 */
class SelfBookingReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Booking $booking) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channelFor($this->booking->clinic_id));
    }

    /** The one place this channel name is spelled. */
    public static function channelFor(int $clinicId): string
    {
        return "clinic.{$clinicId}";
    }

    /** See SlotsChanged::broadcastAs() — the class name is not what the page listens for. */
    public function broadcastAs(): string
    {
        return 'SelfBookingReceived';
    }

    /**
     * Enough to write a line of notice, and no more. Anything the secretary
     * needs beyond this is one tap away on the queue, which is already
     * scoped to her clinic and already checks what she may see.
     */
    public function broadcastWith(): array
    {
        $booking = $this->booking->loadMissing(['patient', 'visitType']);

        return [
            'booking_id' => $booking->id,
            'patient_name' => $booking->patient?->name,
            'visit_type' => $booking->visitType?->name,
            'date' => $booking->visit_date->toDateString(),
            'start_time' => $booking->start_at->format('H:i'),
        ];
    }
}
