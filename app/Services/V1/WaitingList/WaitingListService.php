<?php

namespace App\Services\V1\WaitingList;

use App\Enums\ApiErrorCode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\WaitingListStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitType;
use App\Models\WaitingListEntry;
use App\Services\V1\Booking\ClinicDayLock;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Patients\PatientService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every rule of the waiting list for a full day.
 *
 * A patient on the list holds nothing. If a slot opens that day the secretary
 * calls them and books it as any other booking — BookingService then takes them
 * off the list (settleForBooking) and sends "booked from the waiting list"
 * instead of the usual confirmation. When the day's last period ends, whoever
 * is still on it is told no slot opened (closeEndedDays).
 *
 * Patients join on the public page (PatientBookingService). The secretary
 * can also put a patient on it, from /app or the mobile API (addByClinic) —
 * someone who phoned in for a full day, today or within the week. Both go through join(), so the cap, the lock
 * and the "you are on the list" message are the same either way.
 */
class WaitingListService
{
    public function __construct(
        private readonly ClinicDayLock $lock,
        private readonly SlotAvailabilityService $slots,
        private readonly WhatsAppMessagingService $messaging,
        private readonly PatientService $patients,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Who is on a day's list, first to join first.
     *
     * @return Collection<int, WaitingListEntry>
     */
    public function forDay(Clinic $clinic, string $date, ?Branch $branch = null): Collection
    {
        // One branch's list for one day (ELA-21).
        return $clinic->waitingListEntries()
            ->where('branch_id', $this->branchId($clinic, $branch))
            ->with(['patient', 'visitType'])
            ->waiting()
            ->onDate($date)
            ->orderBy('id')
            ->get();
    }

    public function countOn(Clinic $clinic, string $date, ?Branch $branch = null): int
    {
        return $clinic->waitingListEntries()
            ->where('branch_id', $this->branchId($clinic, $branch))
            ->waiting()
            ->onDate($date)
            ->count();
    }

    /**
     * Everyone on a list for today or later — the clinic app's badge.
     */
    public function upcomingCount(Clinic $clinic, ?Branch $branch = null): int
    {
        return $clinic->waitingListEntries()
            ->where('branch_id', $this->branchId($clinic, $branch))
            ->waiting()
            ->whereDate('visit_date', '>=', $clinic->currentDay()->toDateString())
            ->count();
    }

    /**
     * 1 for the first to join. Counts only those still waiting, so a patient
     * moves up as the people ahead of them are booked or leave.
     */
    public function position(WaitingListEntry $entry): int
    {
        return WaitingListEntry::query()
            ->where('clinic_id', $entry->clinic_id)
            ->where('branch_id', $entry->branch_id)
            ->waiting()
            ->onDate($entry->visit_date->toDateString())
            ->where('id', '<=', $entry->id)
            ->count();
    }

    /** The cap is the clinic's, counted per branch and day (ELA-21). */
    public function isFull(Clinic $clinic, string $date, ?Branch $branch = null): bool
    {
        return $this->countOn($clinic, $date, $branch) >= $clinic->waitingListMaxPerDay();
    }

    /** This patient's place on that day's list, if they have one. */
    public function entryFor(Clinic $clinic, Patient $patient, string $date, ?Branch $branch = null): ?WaitingListEntry
    {
        return $clinic->waitingListEntries()
            ->where('branch_id', $this->branchId($clinic, $branch))
            ->with(['patient', 'visitType'])
            ->waiting()
            ->where('patient_id', $patient->id)
            ->onDate($date)
            ->first();
    }

    /**
     * Whether this patient has left that day's list often enough that they
     * may not join it again (config: clinic.waiting_list.max_leaves_per_day).
     * Only the times they left themselves count — a removal by the clinic is
     * not theirs to answer for.
     */
    public function hasLeftTooOften(Clinic $clinic, Patient $patient, string $date, ?Branch $branch = null): bool
    {
        $left = $clinic->waitingListEntries()
            ->where('branch_id', $this->branchId($clinic, $branch))
            ->where('patient_id', $patient->id)
            ->onDate($date)
            ->where('status', WaitingListStatus::LEFT)
            ->count();

        return $left >= (int) config('clinic.waiting_list.max_leaves_per_day');
    }

    public function find(Clinic $clinic, int $entryId): WaitingListEntry
    {
        $entry = $clinic->waitingListEntries()
            ->with(['patient', 'visitType'])
            ->waiting()
            ->whereKey($entryId)
            ->first();

        // A team member reaches only her branches' lists (ELA-21), as with
        // bookings: another branch's entry is "not found".
        $actor = request()->user();

        if ($entry !== null && $actor instanceof User && $actor->isSecretary()
            && ! $actor->accessibleBranches($clinic)->contains('id', $entry->branch_id)) {
            $entry = null;
        }

        if ($entry === null) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_ENTRY_NOT_FOUND,
                __('waiting_list.not_found'),
                http: 404,
            );
        }

        return $entry;
    }

    /**
     * When the day's last period closes, as an instant — or null when the
     * clinic does not open that day at all.
     */
    public function dayEndsAt(Clinic $clinic, Carbon $date, ?Branch $branch = null): ?Carbon
    {
        $branch ??= $clinic->firstBranch();

        if ($this->slots->isHoliday($clinic, $date, $branch)) {
            return null;
        }

        $schedule = $branch->scheduleFor(DayOfWeek::fromDate($date));

        if ($schedule === null || ! $schedule->is_open) {
            return null;
        }

        $end = null;

        foreach ($schedule->periods as $period) {
            [, $closes] = $period->boundsOn($date, $clinic->timezone);
            $end = $end === null || $closes->gt($end) ? $closes : $end;
        }

        return $end;
    }

    /**
     * Whether a list for this day can still be joined: the clinic opens that
     * day, it is inside the booking window, and its last period has not
     * ended yet.
     */
    public function isJoinable(Clinic $clinic, string $date, ?Branch $branch = null): bool
    {
        $day = Carbon::parse($date, $clinic->timezone)->startOfDay();

        if (! $this->slots->isWithinWindow($clinic, $day)) {
            return false;
        }

        $end = $this->dayEndsAt($clinic, $day, $branch);

        return $end !== null && Carbon::now($clinic->timezone)->lt($end);
    }

    /**
     * The last day the secretary may add to: today and the days after it,
     * `clinic.waiting_list.clinic_add_days` in all.
     */
    public function lastClinicAddDay(Clinic $clinic): Carbon
    {
        return $this->slots->today($clinic)
            ->addDays(max(1, (int) config('clinic.waiting_list.clinic_add_days')) - 1);
    }

    /**
     * Whether the secretary may add to this day's list at all: joinable, and
     * no further ahead than the week. Not whether it is full — that depends
     * on the visit type, and is asked when she adds.
     */
    public function canClinicAddOn(Clinic $clinic, string $date, ?Branch $branch = null): bool
    {
        return $this->isWithinClinicAddWindow($clinic, $date) && $this->isJoinable($clinic, $date, $branch);
    }

    private function isWithinClinicAddWindow(Clinic $clinic, string $date): bool
    {
        $day = Carbon::parse($date, $clinic->timezone)->toDateString();

        return $day >= $this->slots->today($clinic)->toDateString()
            && $day <= $this->lastClinicAddDay($clinic)->toDateString();
    }

    /*
    |--------------------------------------------------------------------------
    | Joining and leaving
    |--------------------------------------------------------------------------
    */

    /**
     * Puts a patient on a day's list and tells them so.
     *
     * Already on it: their place is handed back, with no second message.
     * Already booked that day: refused — there is nothing to wait for.
     *
     * The count and the write happen under the same lock bookings take for
     * the day, so two patients joining at once cannot both take the last
     * place.
     */
    public function join(
        Clinic $clinic,
        Patient $patient,
        ?VisitType $visitType,
        string $date,
        BookingSource $source,
        ?User $actor = null,
        ?Branch $branch = null,
    ): WaitingListEntry {
        $branch ??= $clinic->firstBranch();
        $this->assertEnabled($clinic, $branch);

        $day = Carbon::parse($date, $clinic->timezone)->startOfDay();
        $date = $day->toDateString();

        if (! $this->isJoinable($clinic, $date, $branch)) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_CLOSED,
                __('waiting_list.closed'),
                http: 409,
            );
        }

        $joined = false;

        $entry = $this->lock->claim($clinic, $day, function () use (
            $clinic, $patient, $visitType, $date, $source, $actor, $branch, &$joined
        ): WaitingListEntry {
            $existing = $this->entryFor($clinic, $patient, $date, $branch);

            if ($existing !== null) {
                return $existing;
            }

            if ($this->hasLeftTooOften($clinic, $patient, $date, $branch)) {
                throw ApiException::make(
                    ApiErrorCode::WAITING_LIST_LEFT_TOO_OFTEN,
                    __('waiting_list.left_too_often'),
                    details: ['max_leaves_per_day' => (int) config('clinic.waiting_list.max_leaves_per_day')],
                    http: 409,
                );
            }

            $booked = $patient->bookings()
                ->where('clinic_id', $clinic->id)
                ->onDate($date)
                ->whereIn('status', BookingStatus::pending())
                ->exists();

            if ($booked) {
                throw ApiException::make(
                    ApiErrorCode::WAITING_LIST_ALREADY_BOOKED,
                    __('waiting_list.already_booked'),
                    http: 409,
                );
            }

            if ($this->isFull($clinic, $date, $branch)) {
                throw ApiException::make(
                    ApiErrorCode::WAITING_LIST_FULL,
                    __('waiting_list.full'),
                    details: ['max_per_day' => $clinic->waitingListMaxPerDay()],
                    http: 409,
                );
            }

            $joined = true;

            return $clinic->waitingListEntries()->create([
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'visit_type_id' => $visitType?->id,
                'visit_date' => $date,
                'status' => WaitingListStatus::WAITING,
                'source' => $source,
                'created_by' => $actor?->id,
            ]);
        });

        // Outside the lock, like the booking confirmation: a queued message
        // must never outlive a rolled-back write.
        if ($joined) {
            $this->messaging->sendWaitingListJoined($clinic, $entry->load('patient'));
        }

        return $entry->load(['patient', 'visitType']);
    }

    /**
     * The secretary puts a patient on a day's list — someone who called for
     * a day that is full.
     *
     * The patient is an existing one (`$patientId`) or found or created by
     * phone, as a booking does. Every rule of join() applies; the entry is
     * marked as added by the clinic and records who added it.
     *
     * One more, as on the public page: the day must really be full for that
     * visit type. With a free slot the secretary books them instead; a list
     * entry there would only end in "no slot opened" on a day that had one.
     * Checked before a new patient is created, so a refusal leaves nothing.
     *
     * And the day must be today or within the week after it — however far
     * the clinic's booking window reaches.
     */
    public function addByClinic(
        Clinic $clinic,
        User $actor,
        string $date,
        int $visitTypeId,
        ?int $patientId = null,
        ?string $name = null,
        ?string $phone = null,
        ?Branch $branch = null,
    ): WaitingListEntry {
        $branch ??= $clinic->firstBranch();
        $this->assertEnabled($clinic, $branch);
        $this->assertWithinClinicAddWindow($clinic, $date);

        $visitType = $this->activeVisitType($clinic, $visitTypeId);
        $this->assertDayIsFull($clinic, $visitType, $date, $branch);

        $patient = $patientId !== null
            ? $this->patients->findById($clinic, $patientId)
            : $this->patients->findOrCreate($clinic, trim((string) $name), $this->patients->parsePhone($clinic, (string) $phone));

        try {
            return $this->join($clinic, $patient, $visitType, $date, BookingSource::CLINIC, $actor, $branch);
        } catch (ApiException $e) {
            // join() words this one to the patient, who is the one leaving.
            if ($e->errorCode === ApiErrorCode::WAITING_LIST_LEFT_TOO_OFTEN) {
                throw ApiException::make(
                    ApiErrorCode::WAITING_LIST_LEFT_TOO_OFTEN,
                    __('waiting_list.left_too_often_staff'),
                    details: $e->details,
                    http: 409,
                );
            }

            throw $e;
        }
    }

    private function assertWithinClinicAddWindow(Clinic $clinic, string $date): void
    {
        if ($this->isWithinClinicAddWindow($clinic, $date)) {
            return;
        }

        $last = $this->lastClinicAddDay($clinic)->toDateString();

        throw ApiException::make(
            ApiErrorCode::WAITING_LIST_OUTSIDE_ADD_WINDOW,
            __('waiting_list.outside_add_window', ['last' => $last]),
            details: ['last_date' => $last],
            http: 422,
        );
    }

    /**
     * Refuses a day that still has a free slot for this visit type. A day that
     * is closed or outside the window is left to join(), which refuses it as
     * closed.
     */
    private function assertDayIsFull(Clinic $clinic, VisitType $visitType, string $date, Branch $branch): void
    {
        $day = Carbon::parse($date, $clinic->timezone)->startOfDay();

        if ($this->slots->for($clinic, $day, $visitType, branch: $branch)->hasAvailableSlot()) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_DAY_NOT_FULL,
                __('waiting_list.day_not_full_staff', ['type' => $visitType->name]),
                http: 409,
            );
        }
    }

    /** The patient took themselves off. */
    public function leave(WaitingListEntry $entry): void
    {
        $this->takeOff($entry, WaitingListStatus::LEFT);
    }

    /** The secretary took them off. */
    public function remove(WaitingListEntry $entry): void
    {
        $this->takeOff($entry, WaitingListStatus::REMOVED);
    }

    private function takeOff(WaitingListEntry $entry, WaitingListStatus $status): void
    {
        if (! $entry->isWaiting()) {
            return;
        }

        $entry->update(['status' => $status, 'left_at' => now()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Booked, and closed
    |--------------------------------------------------------------------------
    */

    /**
     * A booking was just written or moved: whoever it is for comes off that
     * day's list.
     *
     * `$entryId` is the entry the secretary booked from. That booking must be
     * on the entry's own day — the list is for that day only, and any booking
     * the patient has on another day is left as it is. It is refused when the
     * patient already has a booking that day: there is nothing left to wait
     * for, and a second booking would be a duplicate.
     *
     * Without an entry, the patient's entry for the booked day is settled, so
     * a patient booked the ordinary way — or moved onto that day — never also
     * stays on its list.
     *
     * Runs inside BookingService's day lock and transaction, so a booking
     * that rolls back leaves the list as it was. Returns the entry the
     * secretary booked from, or null — the caller sends "booked from the
     * waiting list" only for that.
     */
    public function settleForBooking(Clinic $clinic, Booking $booking, ?int $entryId = null): ?WaitingListEntry
    {
        $date = $booking->visit_date->toDateString();
        $chosen = $entryId === null ? null : $this->assertBookableFrom($clinic, $booking, $entryId);

        $entries = $clinic->waitingListEntries()
            ->waiting()
            ->where('patient_id', $booking->patient_id)
            ->whereDate('visit_date', $date)
            ->get();

        foreach ($entries as $entry) {
            $entry->update([
                'status' => WaitingListStatus::BOOKED,
                'booking_id' => $booking->id,
                'left_at' => now(),
            ]);
        }

        return $chosen?->refresh();
    }

    /**
     * The entry a booking is being made from: this patient's, on the booked
     * day, and with no other booking that day already.
     *
     * An entry that stopped waiting while the secretary was on the phone
     * does not cost the booking. Already booked from the list, it is refused
     * — that is the duplicate this link exists to catch. Left, removed or
     * closed, it is ignored (null): the call still happened and the patient
     * still wants the time, so they are booked the ordinary way and get the
     * ordinary confirmation.
     */
    private function assertBookableFrom(Clinic $clinic, Booking $booking, int $entryId): ?WaitingListEntry
    {
        $entry = $clinic->waitingListEntries()
            ->with(['patient', 'visitType'])
            ->whereKey($entryId)
            ->first();

        if ($entry === null) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_ENTRY_NOT_FOUND,
                __('waiting_list.not_found'),
                http: 404,
            );
        }

        if ($entry->patient_id !== $booking->patient_id) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_ENTRY_NOT_FOUND,
                __('waiting_list.other_patient'),
                http: 422,
            );
        }

        if ($entry->status === WaitingListStatus::BOOKED) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_ALREADY_BOOKED,
                __('waiting_list.already_booked_from_list'),
                details: ['booking_id' => $entry->booking_id],
                http: 409,
            );
        }

        if (! $entry->isWaiting()) {
            return null;
        }

        // Booked at the branch whose list it is, never another (ELA-21).
        if ($entry->branch_id !== $booking->branch_id) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_WRONG_BRANCH,
                __('waiting_list.wrong_branch', ['branch' => $entry->branch?->name]),
                details: ['branch_id' => $entry->branch_id],
                http: 422,
            );
        }

        $date = $booking->visit_date->toDateString();

        if ($entry->visit_date->toDateString() !== $date) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_WRONG_DAY,
                __('waiting_list.wrong_day', ['date' => $entry->visit_date->toDateString()]),
                details: ['waiting_date' => $entry->visit_date->toDateString()],
                http: 422,
            );
        }

        $alreadyBooked = $clinic->bookings()
            ->where('patient_id', $booking->patient_id)
            ->whereKeyNot($booking->id)
            ->onDate($date)
            ->whereIn('status', BookingStatus::pending())
            ->exists();

        if ($alreadyBooked) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_ALREADY_BOOKED,
                __('waiting_list.already_booked'),
                http: 409,
            );
        }

        return $entry;
    }

    /**
     * Closes every list whose day has ended, telling whoever is still on it.
     *
     * The message goes once per entry (`messaged_at`), and not at all when
     * the close comes more than `notify_within_hours` after the day ended —
     * a scheduler that was down should not send news about a day long gone.
     *
     * @return array{closed: int, messaged: int}
     */
    public function closeEndedDays(Clinic $clinic): array
    {
        $now = Carbon::now($clinic->timezone);
        $closed = 0;
        $messaged = 0;

        // An overnight period can end on the next calendar day, so yesterday
        // is still a candidate; anything older is over by definition.
        // Each branch's list ends with that branch's day (ELA-21).
        $lists = $clinic->waitingListEntries()
            ->waiting()
            ->whereDate('visit_date', '<=', $now->toDateString())
            ->get(['visit_date', 'branch_id'])
            ->map(fn ($entry) => [Carbon::parse($entry->visit_date)->toDateString(), $entry->branch_id])
            ->unique(fn (array $list) => $list[0].'#'.$list[1]);
        $branches = $clinic->branches()->get()->keyBy('id');

        foreach ($lists as [$date, $branchId]) {
            $branch = $branches->get($branchId) ?? $clinic->firstBranch();
            $day = Carbon::parse($date, $clinic->timezone)->startOfDay();
            $end = $this->dayEndsAt($clinic, $day, $branch) ?? $day;

            if ($now->lt($end)) {
                continue;
            }

            $notify = $now->diffInMinutes($end, true)
                <= (int) config('clinic.waiting_list.notify_within_hours') * 60;

            $entries = $clinic->waitingListEntries()
                ->where('branch_id', $branch->id)
                ->with('patient')
                ->waiting()
                ->onDate($date)
                ->get();

            foreach ($entries as $entry) {
                $entry->update(['status' => WaitingListStatus::CLOSED, 'left_at' => $now]);
                $closed++;

                if ($notify && $entry->messaged_at === null
                    && $this->messaging->sendWaitingListNoSlot($clinic, $entry) !== null) {
                    $entry->update(['messaged_at' => $now]);
                    $messaged++;
                }
            }
        }

        return ['closed' => $closed, 'messaged' => $messaged];
    }

    /** The branch asked about, or the clinic's first: the only one before branches. */
    private function branchId(Clinic $clinic, ?Branch $branch): ?int
    {
        return $branch?->id ?? $clinic->firstBranchId();
    }

    private function activeVisitType(Clinic $clinic, int $visitTypeId): VisitType
    {
        $visitType = $clinic->visitTypes()->whereKey($visitTypeId)->first();

        if ($visitType === null) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_NOT_FOUND,
                __('settings.visit_type.not_found'),
                http: 404,
            );
        }

        if (! $visitType->is_active) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_INACTIVE,
                __('booking.visit_type_inactive'),
            );
        }

        return $visitType;
    }

    private function assertEnabled(Clinic $clinic, Branch $branch): void
    {
        if (! $branch->offersWaitingList()) {
            throw ApiException::make(
                ApiErrorCode::WAITING_LIST_DISABLED,
                __('waiting_list.disabled'),
                http: 403,
            );
        }
    }
}
