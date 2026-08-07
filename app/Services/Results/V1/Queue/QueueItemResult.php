<?php

namespace App\Services\Results\V1\Queue;

use App\Models\Booking;
use App\Services\Results\ServiceResult;
use App\Services\Results\V1\Booking\BookingResult;

/**
 * A booking as it appears on a queue card: the booking itself, plus its live
 * position and the actions the app may offer.
 */
final class QueueItemResult extends ServiceResult
{
    /**
     * @param  list<string>  $availableActions
     */
    public function __construct(
        private readonly Booking $booking,
        private readonly ?int $queuePosition,
        private readonly array $availableActions,
        private readonly bool $withPrice = false,
    ) {}

    public function toArray(): array
    {
        return (new BookingResult($this->booking, $this->withPrice))->toArray() + [
            // Null for anyone not physically in the clinic — the card shows
            // her appointment time instead (SPEC §4.2).
            'queue_position' => $this->queuePosition,
            'available_actions' => $this->availableActions,
            'arrived_at' => $this->booking->arrived_at?->format('H:i'),
            'contacted_at' => $this->booking->contacted_at?->toAtomString(),
        ];
    }
}
