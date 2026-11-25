<?php

namespace App\Services\V1\Settings;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicHoliday;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Days off on top of the usual week (ELA-20): one date or a range, for every
 * branch or for some of them.
 *
 * "All branches" is stored once per date with no branch, so it also closes a
 * branch added later. A range is one row per date sharing a `group_id`, so
 * every per-date lookup keeps working and the range is removed as one.
 * Nothing here cancels a booking: the count is shown, and the secretary
 * decides.
 */
class HolidayService
{
    /**
     * Upcoming holidays only by default — past ones are noise on the settings
     * screen, but stay in the table so historical days still read correctly.
     * With a branch, only the ones that close it.
     *
     * @return Collection<int, ClinicHoliday>
     */
    public function list(Clinic $clinic, bool $includePast = false, ?Branch $branch = null): Collection
    {
        return ClinicHoliday::query()
            ->where('clinic_id', $clinic->id)
            ->when($branch !== null, fn ($query) => $query->closing($branch))
            ->when(
                ! $includePast,
                fn ($query) => $query->whereDate('date', '>=', $this->today($clinic)->toDateString()),
            )
            ->with('branch')
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>|null  $branchIds  null for every branch
     *
     * @throws ApiException when a date in the range is already off for one of
     *                      those branches, the range is too long, or bookings
     *                      exist and `force` was not passed
     */
    public function create(
        Clinic $clinic,
        string $date,
        ?string $note,
        bool $force = false,
        ?string $endDate = null,
        ?array $branchIds = null,
        ?User $actor = null,
    ): ClinicHoliday {
        $dates = $this->dates($date, $endDate);
        $branchIds = $this->scope($clinic, $branchIds, $actor);

        $taken = $this->alreadyOff($clinic, $dates, $branchIds);

        if ($taken !== []) {
            throw ApiException::make(
                ApiErrorCode::HOLIDAY_ALREADY_EXISTS,
                __('settings.holiday.already_exists'),
                details: ['dates' => $taken],
                http: 409,
            );
        }

        // Closing days that already have patients booked is almost always a
        // mistake. Surface it, and make the secretary confirm rather than
        // silently orphaning them. Counted across the clinic until bookings
        // carry their branch (ELA-18): with one branch, the same thing.
        $bookings = $clinic->bookings()
            ->whereDate('visit_date', '>=', $dates[0])
            ->whereDate('visit_date', '<=', end($dates))
            ->whereIn('status', BookingStatus::pending())
            ->count();

        if ($bookings > 0 && ! $force) {
            throw ApiException::make(
                ApiErrorCode::HOLIDAY_HAS_BOOKINGS,
                __('settings.holiday.has_bookings', ['count' => $bookings]),
                details: ['bookings_count' => $bookings, 'date' => $dates[0], 'end_date' => end($dates)],
                http: 409,
            );
        }

        return DB::transaction(function () use ($clinic, $dates, $branchIds, $note): ClinicHoliday {
            $group = count($dates) > 1 || ($branchIds !== null && count($branchIds) > 1) ? (string) Str::uuid() : null;
            $first = null;

            foreach ($dates as $day) {
                foreach ($branchIds ?? [null] as $branchId) {
                    $holiday = $clinic->holidays()->create([
                        'branch_id' => $branchId,
                        'group_id' => $group,
                        'date' => $day,
                        'note' => $note,
                    ]);
                    $first ??= $holiday;
                }
            }

            return $first->load('branch');
        });
    }

    /**
     * Removes the holiday, and the rest of its range or branches with it.
     */
    public function delete(Clinic $clinic, int $holidayId, ?User $actor = null): void
    {
        $holiday = $clinic->holidays()->whereKey($holidayId)->first();

        if ($holiday === null || ! $this->mayChange($holiday, $actor)) {
            throw ApiException::make(
                ApiErrorCode::HOLIDAY_NOT_FOUND,
                __('settings.holiday.not_found'),
                http: 404,
            );
        }

        $holiday->group_id === null
            ? $holiday->delete()
            : $clinic->holidays()->where('group_id', $holiday->group_id)->delete();
    }

    /**
     * @return list<string> Y-m-d, from first to last
     */
    private function dates(string $date, ?string $endDate): array
    {
        $from = Carbon::parse($date)->startOfDay();
        $to = $endDate === null ? $from->copy() : Carbon::parse($endDate)->startOfDay();
        $max = (int) config('clinic.holidays.max_range_days');

        if ($to->lt($from) || $from->diffInDays($to) + 1 > $max) {
            throw ApiException::make(
                ApiErrorCode::HOLIDAY_RANGE_INVALID,
                __('settings.holiday.range_invalid', ['days' => $max]),
                http: 422,
            );
        }

        $dates = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * The branches the holiday closes, or null for every branch. A team
     * member works within their branches only (ELA-17): their "all" is all
     * of theirs, and any other branch is ignored.
     *
     * @param  list<int>|null  $branchIds
     * @return list<int>|null
     */
    private function scope(Clinic $clinic, ?array $branchIds, ?User $actor): ?array
    {
        $allowed = $actor === null || $actor->isOwner()
            ? $clinic->branches()->pluck('id')
            : $actor->accessibleBranches($clinic)->pluck('id');

        $everyBranch = $allowed->count() === $clinic->branches()->count();

        if ($branchIds === null) {
            return $everyBranch ? null : $allowed->sort()->values()->all();
        }

        $chosen = $allowed->intersect($branchIds)->sort()->values()->all();

        if ($chosen === []) {
            throw ApiException::make(
                ApiErrorCode::HOLIDAY_BRANCH_REQUIRED,
                __('settings.holiday.branch_required'),
                http: 422,
            );
        }

        return $chosen;
    }

    /**
     * Dates in the range already off for any of those branches: by a holiday
     * of their own, or by one for every branch. "Every branch" clashes with
     * any holiday that day.
     *
     * @param  list<string>  $dates
     * @param  list<int>|null  $branchIds
     * @return list<string>
     */
    private function alreadyOff(Clinic $clinic, array $dates, ?array $branchIds): array
    {
        return $clinic->holidays()
            ->whereIn(DB::raw('DATE(date)'), $dates)
            ->when($branchIds !== null, fn ($query) => $query->where(
                fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds),
            ))
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * A team member may remove a holiday only if it closes nothing but their
     * own branches; an "every branch" one only if they have every branch.
     */
    private function mayChange(ClinicHoliday $holiday, ?User $actor): bool
    {
        if ($actor === null || $actor->isOwner()) {
            return true;
        }

        $mine = $actor->accessibleBranches($holiday->clinic)->pluck('id');
        $closes = $holiday->group_id === null
            ? collect([$holiday->branch_id])
            : ClinicHoliday::query()->where('group_id', $holiday->group_id)->pluck('branch_id')->unique();

        if ($closes->contains(null)) {
            return $mine->count() === $holiday->clinic->branches()->count();
        }

        return $closes->diff($mine)->isEmpty();
    }

    private function today(Clinic $clinic): Carbon
    {
        return Carbon::now($clinic->timezone)->startOfDay();
    }
}
