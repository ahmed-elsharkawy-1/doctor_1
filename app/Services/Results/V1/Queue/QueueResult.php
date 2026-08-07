<?php

namespace App\Services\Results\V1\Queue;

use App\Models\Booking;
use App\Models\Clinic;
use App\Services\Results\ServiceResult;
use App\Services\V1\Queue\QueueService;
use App\Support\Wire;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class QueueResult extends ServiceResult
{
    /**
     * @param  Collection<int, Booking>  $queue
     */
    public function __construct(
        private readonly Clinic $clinic,
        private readonly Carbon $date,
        private readonly Collection $queue,
        private readonly QueueService $service,
        private readonly bool $isOpen,
        private readonly bool $isHoliday,
        private readonly int $awaitingRebooking,
        private readonly bool $withPrice = false,
    ) {}

    public function toArray(): array
    {
        $positions = $this->service->positions($this->queue);

        return [
            'date' => Wire::date($this->date),
            'is_open' => $this->isOpen,
            'is_holiday' => $this->isHoliday,
            'counts' => $this->service->counts($this->queue),
            // Drives the "N مريضة محتاجة معاد جديد" banner on the home screen.
            'awaiting_rebooking_count' => $this->awaitingRebooking,
            'items' => $this->queue
                ->map(fn (Booking $booking) => (new QueueItemResult(
                    $booking,
                    $positions[$booking->id] ?? null,
                    $this->service->availableActions($booking),
                    $this->withPrice,
                ))->toArray())
                ->values()
                ->all(),
        ];
    }
}
