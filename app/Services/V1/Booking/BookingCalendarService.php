<?php

namespace App\Services\V1\Booking;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Clinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BookingCalendarService
{
    public function __construct(private readonly SlotAvailabilityService $slots) {}

    /**
     * @return array{from: Carbon, to: Carbon, days: list<array<string, mixed>>, bookings: array<string, list<Booking>>}
     */
    public function range(Clinic $clinic, ?Carbon $from = null, ?Carbon $to = null, ?BookingStatus $status = null): array
    {
        $from ??= $this->slots->today($clinic);
        $to ??= $from->copy()->addDays($clinic->booking_window_days - 1);

        $from = $this->clinicDate($clinic, $from);
        $to = $this->clinicDate($clinic, $to);

        $bookings = $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('visit_date')
            ->orderBy('start_at')
            ->get();

        $counts = $this->countsByDay($bookings);
        $days = $this->days($clinic, $from, $to, $counts);

        $cards = $bookings
            ->when($status !== null, fn (Collection $items) => $items->where('status', $status))
            ->groupBy(fn (Booking $booking) => Carbon::parse($booking->visit_date)->toDateString())
            ->map(fn (Collection $group) => $group->values()->all())
            ->all();

        ksort($cards);

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'bookings' => $cards,
        ];
    }

    /**
     * @return array{total: int, booked: int, arrived: int, with_doctor: int, done: int, cancelled: int, no_show: int}
     */
    public function emptyCounts(): array
    {
        return [
            'total' => 0,
            'booked' => 0,
            'arrived' => 0,
            'with_doctor' => 0,
            'done' => 0,
            'cancelled' => 0,
            'no_show' => 0,
        ];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, array<string, int>>
     */
    private function countsByDay(Collection $bookings): array
    {
        return $bookings
            ->groupBy(fn (Booking $booking) => Carbon::parse($booking->visit_date)->toDateString())
            ->map(function (Collection $group): array {
                $counts = $this->emptyCounts();
                $counts['total'] = $group->count();

                foreach (BookingStatus::cases() as $status) {
                    $counts[$status->value] = $group->where('status', $status)->count();
                }

                return $counts;
            })
            ->all();
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     * @return list<array<string, mixed>>
     */
    private function days(Clinic $clinic, Carbon $from, Carbon $to, array $counts): array
    {
        $holidays = $clinic->holidays()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')
            ->map(static fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();

        $openDays = $clinic->schedules()
            ->where('is_open', true)
            ->pluck('day_of_week')
            ->map(static fn ($day) => $day instanceof DayOfWeek ? $day->value : (int) $day)
            ->flip();

        $today = $this->slots->today($clinic)->toDateString();
        $days = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $day = DayOfWeek::fromDate($date);
            $isHoliday = $holidays->has($key);

            $days[] = [
                'date' => $date->copy(),
                'day' => $day,
                'is_open' => ! $isHoliday && $openDays->has($day->value),
                'is_holiday' => $isHoliday,
                'is_today' => $key === $today,
                'counts' => $counts[$key] ?? $this->emptyCounts(),
            ];
        }

        return $days;
    }

    private function clinicDate(Clinic $clinic, Carbon $date): Carbon
    {
        return Carbon::parse($date->toDateString(), $clinic->timezone)->startOfDay();
    }
}
