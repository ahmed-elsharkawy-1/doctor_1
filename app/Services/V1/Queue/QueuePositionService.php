<?php

namespace App\Services\V1\Queue;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How many patients stand between a patient and the doctor, for the tracking
 * page (SPEC v1.1 §7).
 *
 * The ordering is not re-derived here — it is the same QueueService ordering
 * the clinic sees on its own queue screen, so the patient and the secretary
 * can never be looking at two different queues.
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
        $now = Carbon::now($clinic->timezone);

        if ($booking->visit_date->toDateString() !== $now->toDateString()) {
            return null;
        }

        if ($booking->status->isTerminal()) {
            return null;
        }

        $sorted = $this->queue->sortBookings($this->todaysQueue($booking));

        $index = $sorted->search(fn (Booking $each): bool => $each->id === $booking->id);

        if ($index === false) {
            return null;
        }

        $ahead = $sorted->take($index);

        return new QueuePosition(
            ahead: $index,
            total: $sorted->count(),
            normal: $sorted->where('booking_kind', BookingKind::NORMAL)->count(),
            emergency: $sorted->where('booking_kind', BookingKind::EMERGENCY)->count(),
            expectedAt: $this->expectedAt($booking, $ahead, $now, $clinic->timezone),
            // In the clinic, not merely due: only then is it their turn.
            hasArrived: in_array($booking->status, [
                BookingStatus::ARRIVED,
                BookingStatus::WITH_DOCTOR,
            ], true),
        );
    }

    /**
     * @return Collection<int, Booking>
     */
    private function todaysQueue(Booking $booking): Collection
    {
        return $booking->clinic->bookings()
            ->onDate($booking->visit_date->toDateString())
            ->whereIn('status', BookingStatus::inQueue())
            ->get();
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
