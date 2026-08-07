<?php

namespace App\Services\V1\Queue;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Booking;
use App\Models\Clinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Today's queue — SPEC §4.2.
 *
 * Ordered by **arrival**, not by appointment time: a patient who checks in
 * moves above people booked earlier, and someone booked at 09:00 who turns up
 * at 10:30 goes behind everyone already waiting. The appointment time only
 * controls capacity and when to come.
 */
class QueueService
{
    /**
     * @return Collection<int, Booking>
     */
    public function forDate(Clinic $clinic, Carbon $date, bool $includeCancelled = false): Collection
    {
        return $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->onDate($date->toDateString())
            ->when(
                ! $includeCancelled,
                fn ($query) => $query->where('status', '!=', BookingStatus::CANCELLED),
            )
            ->get()
            ->sortBy(fn (Booking $booking) => $booking->queueSortKey())
            ->values();
    }

    /**
     * The number on the card. Only patients physically in the clinic hold a
     * position — someone who has not arrived is shown her appointment time
     * instead, and a finished or cancelled visit shows neither.
     *
     * @param  Collection<int, Booking>  $queue
     * @return array<int, int> booking id => position
     */
    public function positions(Collection $queue): array
    {
        $positions = [];
        $position = 0;

        foreach ($queue as $booking) {
            if ($booking->status->holdsQueuePosition()) {
                $positions[$booking->id] = ++$position;
            }
        }

        return $positions;
    }

    /**
     * @param  Collection<int, Booking>  $queue
     * @return array{pending: int, done: int, cancelled: int, total: int}
     */
    public function counts(Collection $queue): array
    {
        return [
            // "X لسه ماخلصوش" — everyone not finished and not cancelled.
            'pending' => $queue->filter(
                fn (Booking $booking) => ! $booking->status->isTerminal()
            )->count(),
            'done' => $queue->where('status', BookingStatus::DONE)->count(),
            'cancelled' => $queue->where('status', BookingStatus::CANCELLED)->count(),
            'total' => $queue->count(),
        ];
    }

    /**
     * What the app may offer on each card, so the button rules live in one
     * place rather than being re-implemented in Flutter.
     *
     * @return list<string>
     */
    public function availableActions(Booking $booking): array
    {
        $actions = [];

        $next = $booking->status->next();

        if ($next !== null) {
            $actions[] = match ($next) {
                BookingStatus::ARRIVED => 'arrive',
                BookingStatus::WITH_DOCTOR => 'call_in',
                BookingStatus::DONE => 'complete',
                default => 'advance',
            };
        }

        if ($booking->status === BookingStatus::BOOKED) {
            // The card shows the phone and a call button before she arrives.
            $actions[] = 'call';
        }

        if ($booking->status->isEditable()) {
            $actions[] = 'edit';
        }

        if ($booking->canBeCancelled()) {
            $actions[] = 'no_show';
            $actions[] = 'cancel';
        }

        return $actions;
    }

    /**
     * Patients whose booking was cancelled by a postponement and who have not
     * been given a new appointment yet (SPEC §4.5).
     *
     * @return Collection<int, Booking>
     */
    public function awaitingRebooking(Clinic $clinic): Collection
    {
        return $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->awaitingRebooking()
            ->orderBy('start_at')
            ->get();
    }

    public function awaitingRebookingCount(Clinic $clinic): int
    {
        return $clinic->bookings()->awaitingRebooking()->count();
    }

    /**
     * Today's patients who can still be postponed — booked or arrived.
     *
     * @return Collection<int, Booking>
     */
    public function postponeCandidates(Clinic $clinic, Carbon $date): Collection
    {
        return $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->onDate($date->toDateString())
            ->pending()
            ->orderBy('start_at')
            ->get();
    }

    /**
     * @return CancelReason[]
     */
    public function selectableCancelReasons(): array
    {
        return CancelReason::selectable();
    }
}
