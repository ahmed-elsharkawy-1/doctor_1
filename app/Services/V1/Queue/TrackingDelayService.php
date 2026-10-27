<?php

namespace App\Services\V1\Queue;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * Whether the tracking page tells a waiting patient the clinic is running late,
 * and by how much.
 *
 * Built on the estimate (QueuePositionService): the lateness is the estimate
 * minus the appointment, less the clinic's reduction, rounded so the number
 * does not change on every refresh. It shows only when that is still more than
 * the clinic's threshold — a small slip is not worth alarming anyone over.
 *
 * Only while someone is still ahead. With nobody ahead the estimate is just
 * "now", so any lateness would be the patient's own, not the clinic's.
 */
class TrackingDelayService
{
    public function for(Booking $booking, ?QueuePosition $position): ?TrackingDelay
    {
        if (! config('clinic.tracking.show_estimate')) {
            return null;
        }

        if ($position === null || $position->expectedAt === null || $position->ahead === 0) {
            return null;
        }

        // An emergency has no appointment to be late against.
        if ($booking->start_at === null || ! in_array($booking->status, BookingStatus::pending(), true)) {
            return null;
        }

        $clinic = $booking->clinic;
        $bookedAt = Carbon::parse($booking->start_at->format('Y-m-d H:i:s'), $clinic->timezone);
        $gap = (int) floor($bookedAt->diffInMinutes($position->expectedAt, absolute: false));

        $step = max(1, (int) config('clinic.tracking.delay.round_to'));
        $minutes = (int) (round(($gap - $clinic->trackingDelayReductionMinutes()) / $step) * $step);

        if ($minutes <= $clinic->trackingDelayThresholdMinutes()) {
            return null;
        }

        $expectedAt = $booking->start_at->copy()->addMinutes($minutes);

        return new TrackingDelay(
            minutes: $minutes,
            bookedAt: $booking->start_at->copy(),
            expectedAt: $expectedAt,
            expectedAfterMidnight: $expectedAt->toDateString() !== $booking->visit_date->toDateString(),
        );
    }
}
