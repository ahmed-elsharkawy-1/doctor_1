<?php

namespace App\Services\Results\V1\Booking;

use App\Models\Booking;
use App\Services\Results\ServiceResult;
use App\Services\V1\Queue\QueueService;
use App\Support\Wire;
use Illuminate\Support\Carbon;

final class BookingCalendarResult extends ServiceResult
{
    /**
     * @param  array{from: Carbon, to: Carbon, days: list<array<string, mixed>>, bookings: array<string, list<Booking>>}  $calendar
     */
    public function __construct(
        private readonly array $calendar,
        private readonly QueueService $queue,
        private readonly bool $withPrice = false,
    ) {}

    public function toArray(): array
    {
        return [
            'range' => [
                'from' => $this->calendar['from']->toDateString(),
                'to' => $this->calendar['to']->toDateString(),
            ],
            'days' => array_map(fn (array $day): array => $this->day($day), $this->calendar['days']),
            'bookings' => $this->bookings(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function day(array $day): array
    {
        /** @var Carbon $date */
        $date = $day['date'];

        return [
            'date' => Wire::date($date),
            'day_of_week' => Wire::enum($day['day'], $day['day']->label()),
            'day_number' => (int) $date->format('j'),
            'is_open' => $day['is_open'],
            'is_holiday' => $day['is_holiday'],
            'is_today' => $day['is_today'],
            'counts' => $day['counts'],
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function bookings(): array
    {
        $days = [];

        foreach ($this->calendar['bookings'] as $date => $bookings) {
            $days[$date] = array_map(
                fn (Booking $booking): array => (new BookingCardResult($booking, $this->queue, $this->withPrice))->toArray(),
                $bookings,
            );
        }

        return $days;
    }
}
