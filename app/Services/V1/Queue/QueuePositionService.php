<?php

namespace App\Services\V1\Queue;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Clinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How many patients stand between a patient and the doctor, for the tracking
 * page (SPEC v1.1 §7).
 *
 * The ordering is not re-derived here — it is the same QueueService ordering
 * the clinic sees on its own queue screen. The one thing the page leaves out
 * is a waiting patient whose appointment comes after this one: the clinic
 * ranks whoever has turned up first, so five evening patients arriving early
 * used to take a 3pm patient sitting at home from 9 ahead to 14 (see
 * `startsLater()`).
 */
class QueuePositionService
{
    public function __construct(
        private readonly QueueService $queue,
    ) {}

    /**
     * Null when there is no position to show: the visit is not today, or it is
     * already finished, cancelled or marked no-show. The page then falls back
     * to the appointment details alone.
     */
    public function for(Booking $booking): ?QueuePosition
    {
        $clinic = $booking->clinic;

        if ($booking->visit_date->toDateString() !== $clinic->currentDay()->toDateString()) {
            return null;
        }

        if ($booking->status->isTerminal()) {
            return null;
        }

        return $this->within($clinic, $this->today($clinic), $booking);
    }

    /**
     * Today's queue in the clinic's own order. Read it once and hand it to
     * `within()` when several patients need a position — a search result
     * must not re-read the whole day for every row.
     *
     * @return Collection<int, Booking>
     */
    public function today(Clinic $clinic): Collection
    {
        return $this->queue->sortBookings(
            $clinic->bookings()
                ->with(['patient', 'visitType'])
                ->onDate($clinic->currentDay()->toDateString())
                ->whereIn('status', BookingStatus::inQueue())
                ->get(),
        );
    }

    /**
     * Where a booking stands in a queue already read by `today()`. Null when
     * it is not in that queue.
     *
     * @param  Collection<int, Booking>  $sorted
     */
    public function within(Clinic $clinic, Collection $sorted, Booking $booking): ?QueuePosition
    {
        $index = $sorted->search(fn (Booking $each): bool => $each->id === $booking->id);

        if ($index === false) {
            return null;
        }

        $before = $sorted->take($index)
            ->reject(fn (Booking $each): bool => $this->startsLater($each, $booking))
            ->values();

        // Whoever is in the room is not "before" them — they are already
        // being seen. Only the rest of their visit still delays the queue,
        // which `expectedAt` counts.
        $inRoom = $booking->status === BookingStatus::WITH_DOCTOR
            ? collect()
            : $before->where('status', BookingStatus::WITH_DOCTOR);
        $ahead = $before->where('status', '!==', BookingStatus::WITH_DOCTOR)->values();

        return new QueuePosition(
            ahead: $ahead->count(),
            total: $sorted->count(),
            normal: $sorted->where('booking_kind', BookingKind::NORMAL)->count(),
            emergency: $sorted->where('booking_kind', BookingKind::EMERGENCY)->count(),
            expectedAt: $this->expectedAt($booking, $before, Carbon::now($clinic->timezone), $clinic->timezone),
            // In the clinic, not merely due: only then is it their turn.
            hasArrived: in_array($booking->status, [
                BookingStatus::ARRIVED,
                BookingStatus::WITH_DOCTOR,
            ], true),
            aheadNormal: $ahead->where('booking_kind', BookingKind::NORMAL)->count(),
            aheadEmergency: $ahead->where('booking_kind', BookingKind::EMERGENCY)->count(),
            withDoctor: $inRoom->count(),
        );
    }

    /**
     * A normal booking still waiting whose appointment is after this one's —
     * ranked ahead on the clinic's screen only because they arrived early.
     *
     * Counting them made the number a patient reads climb for no reason they
     * could see, with everyone before them booked and on time. Emergencies
     * still count, as the design warns. The patient with the doctor is left
     * to `within()`: whoever booked them, that visit is being waited on now.
     */
    private function startsLater(Booking $other, Booking $booking): bool
    {
        if ($other->booking_kind !== BookingKind::NORMAL || $booking->booking_kind !== BookingKind::NORMAL) {
            return false;
        }

        if (! in_array($other->status, BookingStatus::pending(), true)) {
            return false;
        }

        if ($other->start_at === null || $booking->start_at === null) {
            return false;
        }

        return $other->start_at->greaterThan($booking->start_at);
    }

    /**
     * Now plus however long the patients ahead still need, but never earlier
     * than the patient's own appointment — an empty waiting room does not mean
     * the doctor will see a 6pm booking at 5pm.
     *
     * @param  Collection<int, Booking>  $ahead
     */
    private function expectedAt(Booking $booking, Collection $ahead, Carbon $now, string $timezone): Carbon
    {
        $minutes = $ahead->sum(fn (Booking $each): int => $this->remainingMinutes($each, $now, $timezone));

        $expected = $now->copy()->addMinutes($minutes);

        if ($booking->start_at === null) {
            return $expected;
        }

        $startAt = $this->clinicLocal($booking->start_at, $timezone);

        return $expected->greaterThan($startAt) ? $expected : $startAt;
    }

    /**
     * The patient already with the doctor is part way through their visit, so
     * only the remainder of it still delays the queue.
     */
    private function remainingMinutes(Booking $booking, Carbon $now, string $timezone): int
    {
        if ($booking->status !== BookingStatus::WITH_DOCTOR || $booking->called_in_at === null) {
            return $booking->duration_minutes;
        }

        $elapsed = $this->clinicLocal($booking->called_in_at, $timezone)
            ->diffInMinutes($now, absolute: false);

        return (int) max(0, $booking->duration_minutes - $elapsed);
    }

    /**
     * Booking times are stored as clinic-local wall clock, so the zone has to
     * be re-attached before anything is compared against `now`.
     */
    private function clinicLocal(Carbon $time, string $timezone): Carbon
    {
        return Carbon::parse($time->format('Y-m-d H:i:s'), $timezone);
    }
}
