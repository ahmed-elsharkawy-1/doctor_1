<?php

namespace App\Services\Results\V1\Queue;

use App\Models\Booking;
use App\Services\Results\ServiceResult;
use App\Services\V1\Queue\DelayOutcome;
use App\Support\DelayLabel;
use App\Support\Wire;

/**
 * The answer to an emergency delay: every patient who moved, from when to
 * when, and whether their message went out.
 */
final class DelayResult extends ServiceResult
{
    public function __construct(private readonly DelayOutcome $outcome) {}

    public function toArray(): array
    {
        $messaging = $this->outcome->messaging;

        return [
            'moved_count' => $this->outcome->bookings->count(),
            'replayed' => $this->outcome->replayed,
            'items' => $this->outcome->bookings
                ->map(function (Booking $booking): array {
                    $minutes = $this->outcome->minutesByBooking[$booking->id] ?? 0;
                    $previous = $booking->start_at->copy()->subMinutes($minutes);

                    return [
                        'booking_id' => $booking->id,
                        'patient' => $booking->patient === null ? null : [
                            'id' => $booking->patient->id,
                            'code' => $booking->patient->code,
                            'name' => $booking->patient->name,
                        ],
                        'status' => Wire::enum($booking->status, $booking->status->label()),
                        'previous_start_time' => Wire::time($previous),
                        'start_time' => Wire::time($booking->start_at),
                        'end_time' => Wire::time($booking->end_at),
                        'after_midnight' => $booking->startsAfterMidnight(),
                        'delay' => [
                            'minutes' => $minutes,
                            'label' => DelayLabel::for($minutes),
                        ],
                    ];
                })
                ->values()
                ->all(),
            'messaging' => [
                'sent_count' => $messaging['sent_count'] ?? 0,
                'skipped_count' => $messaging['skipped_count'] ?? 0,
                'skipped' => $messaging['skipped'] ?? [],
                'whatsapp_enabled' => $messaging['whatsapp_enabled'] ?? null,
            ],
        ];
    }
}
