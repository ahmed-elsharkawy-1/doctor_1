<?php

namespace App\Services\V1\Queue;

use App\Enums\ApiErrorCode;
use App\Enums\AuthErrorCode;
use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Events\SlotsChanged;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\ClinicDayLock;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Support\DelayLabel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "تأخير طارئ": the doctor is held up, so today's appointments move later.
 *
 * The booking picked moves by the whole delay, and everyone after it only as
 * far as they must to keep from overlapping the one before: a gap in the
 * evening absorbs part of the delay, so a patient after it moves less, or not
 * at all. Done, with-the-doctor, cancelled and no-show bookings stay where
 * they are and their time counts as free. Emergencies have no time to move.
 *
 * One tap, no confirmation step: the app's own dialog is the confirmation.
 * The same tap arriving twice — a double tap, a retried request — is answered
 * with the first result rather than moving everyone again. A genuine second
 * delay, minutes later, moves them again, which is what it is for.
 *
 * Nothing that would end after the end of the clinic day is moved: the whole
 * delay is refused instead. The time it vacates is left open for booking.
 */
class DelayService
{
    public function __construct(
        private readonly ClinicDayLock $lock,
        private readonly WhatsAppMessagingService $messaging,
    ) {}

    /**
     * The delays offered, with their wording.
     *
     * @return list<array{minutes: int, label: string}>
     */
    public function options(): array
    {
        return array_map(fn (int $minutes): array => [
            'minutes' => $minutes,
            'label' => DelayLabel::for($minutes),
        ], $this->allowedMinutes());
    }

    /** @return list<int> */
    public function allowedMinutes(): array
    {
        return array_map('intval', (array) config('clinic.delay.options'));
    }

    /**
     * Who a delay can start from today, in time order — everyone it could
     * move. The first is the usual pick: the first patient still waiting.
     *
     * @return Collection<int, Booking>
     */
    public function startingPoints(Clinic $clinic, ?Branch $branch = null): Collection
    {
        return $this->movableOn($clinic, $clinic->currentDay()->toDateString(), $branch?->id ?? $clinic->firstBranchId())
            ->with(['patient', 'visitType'])
            ->get();
    }

    public function delay(Clinic $clinic, int $bookingId, int $minutes): DelayOutcome
    {
        // The same lookup every other booking action uses: a team member
        // reaches only her branches' bookings (ELA-19).
        $from = app(BookingService::class)->find($clinic, $bookingId);

        $day = $clinic->currentDay();
        $replayKey = "booking_delay_{$clinic->id}_{$from->id}_{$minutes}";

        // Inside the day lock, so a double tap waits for the first and then
        // finds its result, and an online booking cannot land in between.
        $replay = null;
        $moved = $this->lock->claim($clinic, $day, function () use ($clinic, $from, $day, $minutes, $replayKey, &$replay): array {
            $replay = Cache::get($replayKey);

            if ($replay !== null) {
                return $replay['minutes'];
            }

            // Read under the lock: the patient may have gone in to the doctor
            // while the dialog was open.
            $this->ensureCanStartDelay($from->refresh(), $day);

            $moved = $this->move($clinic, $from, $minutes);

            Cache::put($replayKey, ['minutes' => $moved, 'messaging' => null], $this->replaySeconds());

            return $moved;
        });

        $bookings = $this->movedBookings($clinic, $moved);

        if ($replay !== null) {
            return new DelayOutcome($bookings, $moved, $replay['messaging'], replayed: true);
        }

        // Outside the lock, as a booking's own confirmation is: a queued
        // message must never outlive a rolled-back move.
        SlotsChanged::dispatch($clinic, $day->toDateString());
        $messaging = $this->messaging->sendDelayed($clinic, $bookings, $moved);

        Cache::put($replayKey, ['minutes' => $moved, 'messaging' => $messaging], $this->replaySeconds());

        return new DelayOutcome($bookings, $moved, $messaging);
    }

    /**
     * Who an emergency delay on that day could move, per branch (ELA-22): the
     * patients still to come with a time. Anyone already with the doctor is
     * never among them.
     *
     * @param  Collection<int, Branch>  $branches
     * @return Collection<int, Collection<int, Booking>> keyed by branch id
     */
    public function candidatesByBranch(Clinic $clinic, Carbon $day, Collection $branches): Collection
    {
        return $branches->mapWithKeys(fn (Branch $branch) => [
            $branch->id => $this->movableOn($clinic, $day->toDateString(), $branch->id)
                ->with(['patient', 'visitType'])
                ->get(),
        ]);
    }

    /**
     * An emergency delay over one or more branches (ELA-22): each branch by
     * its own minutes, from its first patient still to come, leaving out
     * whoever was unticked. The same rule as delay() within a branch: a gap
     * in the day absorbs part of the delay.
     *
     * @param  array<int, array{minutes: int, exclude?: list<int>}>  $plan  branch id => what to do there
     */
    public function delayBranches(Clinic $clinic, User $actor, Carbon $day, array $plan): DelayOutcome
    {
        $day = Carbon::parse($day->toDateString(), $clinic->timezone)->startOfDay();
        $branches = $actor->accessibleBranches($clinic)->keyBy('id');

        if ($day->lt($clinic->currentDay())) {
            throw ApiException::make(ApiErrorCode::BOOKING_NOT_DELAYABLE, __('booking.delay.not_today'), details: ['reason' => 'past_day']);
        }

        foreach ($plan as $branchId => $step) {
            if (! $branches->has($branchId)) {
                throw ApiException::make(ApiErrorCode::BRANCH_NOT_FOUND, __('settings.branch.not_found'), http: 404);
            }

            if (! in_array((int) $step['minutes'], $this->allowedMinutes(), true)) {
                throw ApiException::make(AuthErrorCode::VALIDATION_FAILED, __('booking.delay.minutes_invalid'), http: 422);
            }
        }

        ksort($plan);
        $replayKey = 'booking_delay_branches_'.$clinic->id.'_'.$day->toDateString().'_'.md5(json_encode($plan));

        $replay = null;
        $moved = $this->lock->claim($clinic, $day, function () use ($clinic, $day, $plan, $replayKey, &$replay): array {
            $replay = Cache::get($replayKey);

            if ($replay !== null) {
                return $replay['minutes'];
            }

            $moved = [];

            foreach ($plan as $branchId => $step) {
                $chosen = $this->movableOn($clinic, $day->toDateString(), (int) $branchId)
                    ->whereNotIn('id', $step['exclude'] ?? [])
                    ->get();

                if ($chosen->isNotEmpty()) {
                    $moved += $this->apply($clinic, $this->cascade($chosen, (int) $step['minutes'], $day));
                }
            }

            if ($moved === []) {
                throw ApiException::make(ApiErrorCode::BOOKING_NOT_DELAYABLE, __('booking.delay.nobody_to_move'), details: ['reason' => 'nobody']);
            }

            Cache::put($replayKey, ['minutes' => $moved, 'messaging' => null], $this->replaySeconds());

            return $moved;
        });

        $bookings = $this->movedBookings($clinic, $moved);

        if ($replay !== null) {
            return new DelayOutcome($bookings, $moved, $replay['messaging'], replayed: true);
        }

        SlotsChanged::dispatch($clinic, $day->toDateString());
        $messaging = $this->messaging->sendDelayed($clinic, $bookings, $moved);

        Cache::put($replayKey, ['minutes' => $moved, 'messaging' => $messaging], $this->replaySeconds());

        return new DelayOutcome($bookings, $moved, $messaging);
    }

    private function ensureCanStartDelay(Booking $from, Carbon $day): void
    {
        if ($from->visit_date->toDateString() !== $day->toDateString()) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_DELAYABLE,
                __('booking.delay.not_today'),
                details: ['reason' => 'not_today'],
            );
        }

        if (! $this->isMovable($from)) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_DELAYABLE,
                __('booking.delay.not_delayable'),
                details: ['reason' => 'not_waiting', 'status' => $from->status->value],
            );
        }
    }

    private function isMovable(Booking $booking): bool
    {
        return in_array($booking->status, BookingStatus::pending(), true)
            && $booking->booking_kind === BookingKind::NORMAL
            && $booking->start_at !== null;
    }

    /**
     * Moves the bookings and says by how much, booking id => minutes. Only the
     * ones that actually moved are listed.
     *
     * @return array<int, int>
     */
    private function move(Clinic $clinic, Booking $from, int $minutes): array
    {
        // Only the delayed patient's own branch's day moves (ELA-19): the
        // other waiting room is not running late.
        $later = $this->movableOn($clinic, $from->visit_date->toDateString(), $from->branch_id)
            ->where('start_at', '>=', $from->start_at)
            ->whereKeyNot($from->id)
            ->get();

        return $this->apply($clinic, $this->cascade($later->prepend($from), $minutes, $from->visit_date));
    }

    /**
     * The moves for a run of bookings, earliest first: the first by
     * `$minutes`, each after it to no earlier than the one before it ends —
     * so a gap in the day absorbs part of the delay.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return array<int, array{0: Carbon, 1: Carbon, 2: int}> booking id => [start, end, shift]
     */
    private function cascade(Collection $bookings, int $minutes, Carbon $visitDate): array
    {
        $limit = $visitDate->copy()->addDay()
            ->setTimeFromTimeString((string) config('clinic.end_of_day.closes_at'));

        $plan = [];
        $previousEnd = null;

        foreach ($bookings as $booking) {
            $length = (int) $booking->start_at->diffInMinutes($booking->end_at);
            $start = $previousEnd === null
                ? $booking->start_at->copy()->addMinutes($minutes)
                : $booking->start_at->copy()->max($previousEnd);
            $end = $start->copy()->addMinutes($length);

            if ($end->greaterThan($limit)) {
                throw ApiException::make(
                    ApiErrorCode::DELAY_PAST_END_OF_DAY,
                    __('booking.delay.past_end_of_day', ['time' => $limit->format('g:i')]),
                    details: ['booking_id' => $booking->id, 'ends_at' => $end->format('Y-m-d H:i')],
                );
            }

            $previousEnd = $end;
            $shift = (int) $booking->start_at->diffInMinutes($start);

            if ($shift > 0) {
                $plan[$booking->id] = [$start, $end, $shift];
            }
        }

        return $plan;
    }

    /**
     * Writes the moves. visit_date stays: a patient pushed past midnight is
     * still on this evening's list, as an overnight booking is.
     *
     * @param  array<int, array{0: Carbon, 1: Carbon, 2: int}>  $plan
     * @return array<int, int> booking id => minutes moved
     */
    private function apply(Clinic $clinic, array $plan): array
    {
        $now = Carbon::now($clinic->timezone);

        foreach ($plan as $id => [$start, $end]) {
            $clinic->bookings()->whereKey($id)->update([
                'start_at' => $start,
                'end_at' => $end,
                'updated_at' => $now,
            ]);
        }

        return array_map(fn (array $step): int => $step[2], $plan);
    }

    /**
     * A day's bookings a delay may move, in time order: still waiting, normal,
     * with a time. The one place that rule is written as a query — see
     * isMovable() for the same rule on a single booking.
     *
     * @return HasMany<Booking, Clinic>
     */
    private function movableOn(Clinic $clinic, string $date, ?int $branchId): HasMany
    {
        return $clinic->bookings()
            ->where('branch_id', $branchId)
            ->onDate($date)
            ->pending()
            ->where('booking_kind', BookingKind::NORMAL)
            ->whereNotNull('start_at')
            ->orderBy('start_at')
            ->orderBy('id');
    }

    /**
     * @param  array<int, int>  $moved
     * @return Collection<int, Booking>
     */
    private function movedBookings(Clinic $clinic, array $moved): Collection
    {
        return $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->whereKey(array_keys($moved))
            ->orderBy('start_at')
            ->get();
    }

    private function replaySeconds(): int
    {
        return (int) config('clinic.delay.replay_seconds');
    }
}
