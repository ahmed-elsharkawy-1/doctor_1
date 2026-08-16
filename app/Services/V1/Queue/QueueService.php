<?php

namespace App\Services\V1\Queue;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Booking;
use App\Models\Clinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared booking-card helpers. Calendar cards are ordered by appointment time;
 * rebooking worklists also use appointment time.
 */
class QueueService
{
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
