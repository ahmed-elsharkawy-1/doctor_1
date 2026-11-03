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
 * Counted by appointment, not by who turned up first: the doctor calls by
 * appointment time, so a patient with a later slot who arrives early never
 * gets ahead, and one with an earlier slot is ahead however late they come.
 * Ahead of a normal booking are:
 *
 *  - normal bookings with an earlier appointment (booked first, on a shared
 *    time) that have not gone in to the doctor — arrived or not;
 *  - emergencies once they are in the clinic. One the secretary has added
 *    but who has not arrived is not waited for yet.
 *
 * Ahead of an emergency are only the emergencies that arrived before it.
 *
 * So the number moves only when someone ahead cancels, is marked no-show or
 * goes in to the doctor (down), or a booking lands in an earlier slot or an
 * emergency arrives (up). The clinic's lists (QueueService) are in the same
 * order — both read QueueOrder — so a waiting patient's place on the
 * secretary's screen is the number on their tracking page, give or take an
 * emergency still on the way, which she sees near the top before it counts.
 */
class QueuePositionService
{
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
     * Today's queue, read once. Hand it to `within()` when several patients
     * need a position — a search result must not re-read the whole day for
     * every row, or two rows could disagree.
     *
     * @return Collection<int, Booking>
     */
    public function today(Clinic $clinic): Collection
    {
        return $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->onDate($clinic->currentDay()->toDateString())
            ->whereIn('status', BookingStatus::inQueue())
            ->get();
    }

    /**
     * Where a booking stands in a queue already read by `today()`. Null when
     * it is not in that queue.
     *
     * @param  Collection<int, Booking>  $queue
     */
    public function within(Clinic $clinic, Collection $queue, Booking $booking): ?QueuePosition
    {
        if (! $queue->contains(fn (Booking $each): bool => $each->id === $booking->id)) {
            return null;
        }

        $now = Carbon::now($clinic->timezone);

        // Whoever is in the room is what the clinic is busy with, but they are
        // not ahead of anyone: only the rest of their visit delays the queue.
        $withDoctor = $queue
            ->where('status', BookingStatus::WITH_DOCTOR)
            ->reject(fn (Booking $each): bool => $each->id === $booking->id)
            ->values();

        // The line: everyone still to go in who counts at all — every normal
        // booking, and the emergencies already in the clinic.
        $line = $queue
            ->reject(fn (Booking $each): bool => $each->status === BookingStatus::WITH_DOCTOR)
            ->filter(fn (Booking $each): bool => $each->booking_kind === BookingKind::NORMAL
                || $each->status === BookingStatus::ARRIVED)
            ->reject(fn (Booking $each): bool => $each->id === $booking->id)
            ->values();

        // Their own visit is under way: nobody is ahead any more.
        $ahead = $booking->status === BookingStatus::WITH_DOCTOR
            ? collect()
            : $line->filter(fn (Booking $each): bool => $this->isAhead($each, $booking))->values();

        $total = $line->count() + 1 + $withDoctor->count();
        $emergencies = $line->where('booking_kind', BookingKind::EMERGENCY)->count()
            + $withDoctor->where('booking_kind', BookingKind::EMERGENCY)->count()
            + ($booking->booking_kind === BookingKind::EMERGENCY ? 1 : 0);

        return new QueuePosition(
            ahead: $ahead->count(),
            total: $total,
            normal: $total - $emergencies,
            emergency: $emergencies,
            expectedAt: $this->expectedAt(
                $booking,
                $ahead->concat($withDoctor),
                $now,
                $clinic->timezone,
            ),
            // In the clinic, not merely due: only then is it their turn.
            hasArrived: in_array($booking->status, [
                BookingStatus::ARRIVED,
                BookingStatus::WITH_DOCTOR,
            ], true),
            aheadNormal: $ahead->where('booking_kind', BookingKind::NORMAL)->count(),
            aheadEmergency: $ahead->where('booking_kind', BookingKind::EMERGENCY)->count(),
            // Someone else is in the room: nobody ahead means next, not now.
            doctorBusy: $booking->status !== BookingStatus::WITH_DOCTOR
                && $withDoctor->isNotEmpty(),
            withDoctor: $withDoctor->count(),
        );
    }

    /**
     * Whether `$other`, still in the line, goes in before `$booking`.
     */
    private function isAhead(Booking $other, Booking $booking): bool
    {
        $otherIsEmergency = $other->booking_kind === BookingKind::EMERGENCY;

        if ($booking->booking_kind === BookingKind::NORMAL) {
            // An emergency in the line is always first; normals by appointment.
            return $otherIsEmergency || QueueOrder::appointmentKey($other) < QueueOrder::appointmentKey($booking);
        }

        // An emergency waits only for emergencies in the clinic before it. Not
        // arrived itself, it has no arrival time and sorts after all of them.
        return $otherIsEmergency && QueueOrder::arrivalKey($other) < QueueOrder::arrivalKey($booking);
    }

    /**
     * Now plus however long the patients ahead still need — and whoever is with
     * the doctor, for what is left of their visit — but never earlier than the
     * patient's own appointment: an empty waiting room does not mean the doctor
     * will see a 6pm booking at 5pm.
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
