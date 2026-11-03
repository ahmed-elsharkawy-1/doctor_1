<?php

namespace App\Services\V1\Queue;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Models\Booking;

/**
 * The one order the line is in, shared by the clinic's lists (QueueService)
 * and the patient's tracking number (QueuePositionService), so the two can
 * never disagree.
 *
 * The doctor calls by appointment, not by who turned up first: a patient
 * with a later slot who arrives early never gets ahead, and one with an
 * earlier slot keeps their place however late they come. Emergencies in the
 * clinic go first, in the order they arrived.
 */
final class QueueOrder
{
    /**
     * Where a waiting booking (booked or arrived) stands in the clinic's list:
     * emergencies in the clinic by arrival, then emergencies still on the way
     * — listed near the top so the secretary sees them, though no patient
     * waits for them until they arrive — then everyone else by appointment.
     */
    public static function waitingKey(Booking $booking): string
    {
        if ($booking->booking_kind !== BookingKind::EMERGENCY) {
            return '2|'.self::appointmentKey($booking);
        }

        return $booking->status === BookingStatus::ARRIVED
            ? '0|'.self::arrivalKey($booking)
            : '1|'.self::idKey($booking);
    }

    /**
     * Appointment time, then the order the bookings were made in — fixed, so
     * two bookings on one time never swap places.
     */
    public static function appointmentKey(Booking $booking): string
    {
        return ($booking->start_at?->format('Y-m-d H:i:s') ?? '9999-12-31 23:59:59')
            .'|'.self::idKey($booking);
    }

    /** When they came into the clinic; not yet arrived sorts last. */
    public static function arrivalKey(Booking $booking): string
    {
        return (($booking->queue_entered_at ?? $booking->arrived_at)?->format('Y-m-d H:i:s.u') ?? '9999-12-31 23:59:59')
            .'|'.self::idKey($booking);
    }

    private static function idKey(Booking $booking): string
    {
        return str_pad((string) $booking->id, 12, '0', STR_PAD_LEFT);
    }
}
