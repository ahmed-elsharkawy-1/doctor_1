<?php

namespace App\Services\Results\V1\Booking;

use App\Models\Booking;
use App\Services\Results\ServiceResult;
use App\Services\V1\Queue\QueueService;
use App\Support\Wire;

final class BookingCardResult extends ServiceResult
{
    public function __construct(
        private readonly Booking $booking,
        private readonly QueueService $queue,
        private readonly bool $withPrice = false,
    ) {}

    public function toArray(): array
    {
        $next = $this->booking->status->next();

        return (new BookingResult($this->booking, $this->withPrice))->toArray() + [
            'next_status' => $next === null ? null : Wire::enum($next, $next->label()),
            'available_actions' => $this->queue->availableActions($this->booking),
            'arrived_at' => $this->booking->arrived_at?->format('H:i'),
            'contacted_at' => $this->booking->contacted_at?->toAtomString(),
        ];
    }
}
