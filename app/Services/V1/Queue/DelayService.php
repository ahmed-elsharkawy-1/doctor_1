<?php

namespace App\Services\V1\Queue;

use App\Enums\ApiErrorCode;
use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Events\SlotsChanged;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
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
    public function startingPoints(Clinic $clinic): Collection
    {
        return $this->movableOn($clinic, $clinic->currentDay()->toDateString())
            ->with(['patient', 'visitType'])
            ->get();
    }

    public function delay(Clinic $clinic, int $bookingId, int $minutes): DelayOutcome
    {
        $from = $clinic->bookings()->whereKey($bookingId)->first();

        if ($from === null) {
            throw ApiException::make(ApiErrorCode::BOOKING_NOT_FOUND, __('booking.not_found'), http: 404);
        }

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
        $messaging = $this->messaging->sendDelayed($clinic, $bookings);

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
        $later = $this->movableOn($clinic, $from->visit_date->toDateString())
            ->where('start_at', '>=', $from->start_at)
            ->whereKeyNot($from->id)
            ->get();

        $limit = $from->visit_date->copy()->addDay()
            ->setTimeFromTimeString((string) config('clinic.end_of_day.closes_at'));

        $plan = [];
        $previousEnd = null;

        foreach ($later->prepend($from) as $booking) {
            $length = (int) $booking->start_at->diffInMinutes($booking->end_at);
            $start = $previousEnd === null
                ? $booking->start_at->copy()->addMinutes($minutes)
                : $booking->start_at->copy()->max($previousEnd);
            $end = $start->copy()->addMinutes($length);

            if ($end->greaterThan($limit)) {
                throw ApiException::make(
                    ApiErrorCode::DELAY_PAST_END_OF_DAY,
                    __('booking.delay.past_end_of_day', ['time' => $limit->format('h:i')]),
                    details: ['booking_id' => $booking->id, 'ends_at' => $end->format('Y-m-d H:i')],
                );
            }

            $previousEnd = $end;
            $shift = (int) $booking->start_at->diffInMinutes($start);

            if ($shift > 0) {
                $plan[$booking->id] = [$start, $end, $shift];
            }
        }

        $now = Carbon::now($clinic->timezone);

        // visit_date stays: a patient pushed past midnight is still on this
        // evening's list, as an overnight booking is.
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
    private function movableOn(Clinic $clinic, string $date): HasMany
    {
        return $clinic->bookings()
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
