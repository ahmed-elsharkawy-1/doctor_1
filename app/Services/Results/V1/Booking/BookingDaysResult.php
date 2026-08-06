<?php

namespace App\Services\Results\V1\Booking;

use App\Enums\DayOfWeek;
use App\Services\Results\ServiceResult;
use App\Support\Wire;
use Illuminate\Support\Carbon;

final class BookingDaysResult extends ServiceResult
{
    /**
     * @param  list<array<string, mixed>>  $days
     */
    public function __construct(private readonly array $days) {}

    public function toArray(): array
    {
        return [
            'days' => array_map(
                static function (array $day): array {
                    /** @var Carbon $date */
                    $date = $day['date'];
                    /** @var DayOfWeek $dayOfWeek */
                    $dayOfWeek = $day['day'];

                    return [
                        'date' => Wire::date($date),
                        'day_of_week' => Wire::enum($dayOfWeek, $dayOfWeek->label()),
                        'day_number' => (int) $date->format('j'),
                        'is_open' => $day['is_open'],
                        'is_holiday' => $day['is_holiday'],
                        'is_today' => $day['is_today'],
                        'bookings_count' => $day['bookings_count'],
                        // Not finished yet — the "X لسه ماخلصوش" counter.
                        'pending_count' => $day['pending_count'],
                    ];
                },
                $this->days,
            ),
        ];
    }
}
