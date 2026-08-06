<?php

namespace App\Services\V1\Settings;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\ClinicHoliday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class HolidayService
{
    /**
     * Upcoming holidays only by default — past ones are noise on the settings
     * screen, but stay in the table so historical days still read correctly.
     *
     * @return Collection<int, ClinicHoliday>
     */
    public function list(Clinic $clinic, bool $includePast = false): Collection
    {
        return $clinic->holidays()
            ->when(
                ! $includePast,
                fn ($query) => $query->whereDate('date', '>=', $this->today($clinic)->toDateString()),
            )
            ->orderBy('date')
            ->get();
    }

    /**
     * @throws ApiException when the date is already a holiday, or has bookings
     *                      and `force` was not passed
     */
    public function create(Clinic $clinic, string $date, ?string $note, bool $force = false): ClinicHoliday
    {
        $date = Carbon::parse($date)->toDateString();

        if ($clinic->holidays()->whereDate('date', $date)->exists()) {
            throw ApiException::make(
                ApiErrorCode::HOLIDAY_ALREADY_EXISTS,
                __('settings.holiday.already_exists'),
                http: 409,
            );
        }

        // Closing a day that already has patients booked is almost always a
        // mistake. Surface it, and make the secretary confirm rather than
        // silently orphaning them — the postpone flow is the right tool.
        $bookings = $clinic->bookings()
            ->whereDate('visit_date', $date)
            ->whereIn('status', BookingStatus::pending())
            ->count();

        if ($bookings > 0 && ! $force) {
            throw ApiException::make(
                ApiErrorCode::HOLIDAY_HAS_BOOKINGS,
                __('settings.holiday.has_bookings', ['count' => $bookings]),
                details: ['bookings_count' => $bookings, 'date' => $date],
                http: 409,
            );
        }

        return $clinic->holidays()->create([
            'date' => $date,
            'note' => $note,
        ]);
    }

    public function delete(Clinic $clinic, int $holidayId): void
    {
        $holiday = $clinic->holidays()->whereKey($holidayId)->first();

        if ($holiday === null) {
            throw ApiException::make(
                ApiErrorCode::HOLIDAY_NOT_FOUND,
                __('settings.holiday.not_found'),
                http: 404,
            );
        }

        $holiday->delete();
    }

    private function today(Clinic $clinic): Carbon
    {
        return Carbon::now($clinic->timezone)->startOfDay();
    }
}
