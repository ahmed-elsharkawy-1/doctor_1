<?php

namespace App\Services\Results\V1\Queue;

use App\Models\Booking;
use App\Services\Results\ServiceResult;
use App\Services\Results\V1\Booking\BookingCardResult;
use App\Services\V1\Queue\QueuePosition;
use App\Services\V1\Queue\QueueService;
use App\Support\Wire;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Queue search: each match is the ordinary queue card plus where that
 * patient stands. The top-level `total` is everyone in today's queue; each
 * match's own `total` is the line that patient waits in, and
 * `with_doctor + place + behind` is always that. They differ by the
 * emergencies not yet in the clinic, who are in nobody's line.
 */
final class QueueSearchResult extends ServiceResult
{
    /**
     * @param  Collection<int, Booking>  $queue
     * @param  Collection<int, array{booking: Booking, position: QueuePosition}>  $matches
     */
    public function __construct(
        private readonly Carbon $date,
        private readonly Collection $queue,
        private readonly Collection $matches,
        private readonly QueueService $queueService,
        private readonly bool $withPrice = false,
    ) {}

    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'total' => $this->queue->count(),
            'items' => $this->matches
                ->map(fn (array $match): array => $this->item($match['booking'], $match['position']))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Booking $booking, QueuePosition $position): array
    {
        return [
            'booking' => (new BookingCardResult($booking, $this->queueService, $this->withPrice))->toArray(),
            'place' => $position->place(),
            'ahead' => $position->ahead,
            'ahead_normal' => $position->aheadNormal,
            'ahead_emergency' => $position->aheadEmergency,
            'behind' => $position->behind(),
            // Already in the room, so never part of `ahead`.
            'with_doctor' => $position->withDoctor,
            'total' => $position->total,
            'expected_at' => $position->expectedAt === null ? null : Wire::time($position->expectedAt),
            'is_next' => $position->isNext(),
        ];
    }
}
