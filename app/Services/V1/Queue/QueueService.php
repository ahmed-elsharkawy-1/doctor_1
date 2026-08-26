<?php

namespace App\Services\V1\Queue;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Booking;
use App\Models\Clinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared booking-card helpers. Calendar, home, postpone, and rebooking lists
 * use the same queue-priority ordering.
 */
class QueueService
{
    /**
     * Emergency patients are handled before normal patients in the active
     * waiting list. Appointment time still breaks ties for normal bookings.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Booking>
     */
    public function sortBookings(Collection $bookings): Collection
    {
        return $bookings
            ->sortBy(fn (Booking $booking): array => $this->sortKey($booking))
            ->values();
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

        if ($booking->status === BookingStatus::BOOKED) {
            // The card shows the phone and a call button before she arrives.
            $actions[] = 'call';
        }

        if (! $booking->status->isTerminal() && $booking->patient?->whatsapp_opt_in_at !== null) {
            $actions[] = 'whatsapp';
        }

        if ($booking->status->isEditable()) {
            $actions[] = 'edit';
        }

        if ($booking->status->canAdvanceTo(BookingStatus::NO_SHOW)) {
            $actions[] = 'no_show';
        }

        if ($booking->canBeCancelled()) {
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
        $bookings = $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->awaitingRebooking()
            ->get();

        return $this->sortBookings($bookings);
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
        $bookings = $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->onDate($date->toDateString())
            ->pending()
            ->get();

        return $this->sortBookings($bookings);
    }

    /**
     * @return CancelReason[]
     */
    public function selectableCancelReasons(): array
    {
        return CancelReason::selectable();
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: int}
     */
    private function sortKey(Booking $booking): array
    {
        $statusRank = match ($booking->status) {
            BookingStatus::WITH_DOCTOR => 0,
            BookingStatus::ARRIVED => 1,
            BookingStatus::BOOKED => 2,
            BookingStatus::DONE => 3,
            BookingStatus::CANCELLED => 4,
            BookingStatus::NO_SHOW => 5,
        };

        $kindRank = $booking->booking_kind === BookingKind::EMERGENCY ? 0 : 1;
        $time = $booking->queue_entered_at
            ?? $booking->arrived_at
            ?? $booking->start_at
            ?? $booking->created_at;

        return [
            $statusRank,
            in_array($booking->status, [BookingStatus::ARRIVED, BookingStatus::BOOKED], true) ? $kindRank : 1,
            $time?->format('Y-m-d H:i:s.u') ?? '',
            $booking->id,
        ];
    }
}
