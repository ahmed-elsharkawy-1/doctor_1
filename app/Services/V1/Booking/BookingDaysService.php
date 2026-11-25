<?php

namespace App\Services\V1\Booking;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicHoliday;
use Illuminate\Support\Carbon;

/**
 * The horizontal day strip on the bookings and new-booking screens.
 *
 * One entry per day of the rolling window, each saying whether the clinic is
 * open and how many patients are already on the books.
 */
class BookingDaysService
{
    public function __construct(private readonly SlotAvailabilityService $slots) {}

    /**
     * @return list<array{
     *     date: Carbon,
     *     day: DayOfWeek,
     *     is_open: bool,
     *     is_holiday: bool,
     *     is_today: bool,
     *     bookings_count: int,
     *     pending_count: int
     * }>
     */
    /**
     * @param  int|null  $dayCount  how many days to return. Null is the
     *                              clinic's own booking window, which is what
     *                              every staff-facing caller wants. The public
     *                              booking page passes a shorter one, so the
     *                              secretary keeps room for the people who
     *                              phone her.
     */
    public function window(Clinic $clinic, ?int $dayCount = null, ?Branch $branch = null): array
    {
        // ELA-18: the branch's own days, holidays and bookings; the clinic's
        // first branch when not given.
        $branch ??= $clinic->firstBranch();
        $dayCount = max(1, $dayCount ?? $clinic->booking_window_days);
        $today = $this->slots->today($clinic);
        $days = [];

        $holidays = ClinicHoliday::query()
            ->closing($branch)
            ->whereDate('date', '>=', $today->toDateString())
            ->pluck('date')
            ->map(static fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();

        $openDays = $branch->schedules()
            ->where('is_open', true)
            ->pluck('day_of_week')
            ->map(static fn ($day) => $day instanceof DayOfWeek ? $day->value : (int) $day)
            ->flip();

        $counts = $this->bookingCounts($branch, $today, $dayCount);

        for ($offset = 0; $offset < $dayCount; $offset++) {
            $date = $today->copy()->addDays($offset);
            $key = $date->toDateString();
            $isHoliday = $holidays->has($key);

            $days[] = [
                'date' => $date,
                'day' => DayOfWeek::fromDate($date),
                'is_open' => ! $isHoliday && $openDays->has(DayOfWeek::fromDate($date)->value),
                'is_holiday' => $isHoliday,
                'is_today' => $offset === 0,
                'bookings_count' => $counts[$key]['total'] ?? 0,
                'pending_count' => $counts[$key]['pending'] ?? 0,
            ];
        }

        return $days;
    }

    /**
     * One grouped query for the whole window rather than a count per day.
     *
     * @return array<string, array{total: int, pending: int}>
     */
    private function bookingCounts(Branch $branch, Carbon $today, int $dayCount): array
    {
        $lastDay = $today->copy()->addDays($dayCount - 1);

        return Booking::query()
            ->where('branch_id', $branch->id)
            ->whereBetween('visit_date', [$today->toDateString(), $lastDay->toDateString()])
            ->occupyingSlot()
            ->get(['visit_date', 'status'])
            ->groupBy(fn ($booking) => Carbon::parse($booking->visit_date)->toDateString())
            ->map(fn ($group) => [
                'total' => $group->count(),
                'pending' => $group->whereIn('status', BookingStatus::pending())->count(),
            ])
            ->all();
    }
}
