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
 * The reduction is a safety margin that gets a patient on the road in time,
 * so a patient already in the clinic is shown the estimate without it. And the
 * time shown is never in the past: near the front of the line the reduction
 * can reach back beyond the present.
 *
 * Only while someone is still ahead. With nobody ahead the estimate is just
 * "now", so any lateness would be the patient's own, not the clinic's.
 */
class TrackingDelayService
{
    public function for(Booking $booking, ?QueuePosition $position): ?TrackingDelay
    {
        if (! $booking->clinic->showsTrackingEstimate()) {
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
        $reduction = $booking->status === BookingStatus::ARRIVED ? 0 : $clinic->trackingDelayReductionMinutes();
        $minutes = (int) (round(($gap - $reduction) / $step) * $step);

        if ($minutes <= $clinic->trackingDelayThresholdMinutes()) {
            return null;
        }

        $now = Carbon::now($clinic->timezone);

        if ($bookedAt->copy()->addMinutes($minutes)->lt($now)) {
            $minutes = (int) (ceil($bookedAt->diffInMinutes($now) / $step) * $step);
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
