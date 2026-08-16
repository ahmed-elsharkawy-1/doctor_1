<?php

namespace App\Services\V1\Queue;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\ApiException;
use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * Moves a booking through the six-status lifecycle — SPEC §5.4.
 *
 *   booked -> arrived -> with_doctor -> done
 *   booked/arrived -> no_show
 *
 * Each clinical step stamps its own timestamp for later wait-time reporting.
 */
class BookingStatusService
{
    public function arrive(Booking $booking): Booking
    {
        return $this->advance($booking, BookingStatus::ARRIVED, ['arrived_at' => $this->now($booking)]);
    }

    public function callIn(Booking $booking): Booking
    {
        return $this->advance($booking, BookingStatus::WITH_DOCTOR, ['called_in_at' => $this->now($booking)]);
    }

    public function complete(Booking $booking): Booking
    {
        return $this->advance($booking, BookingStatus::DONE, ['completed_at' => $this->now($booking)]);
    }

    public function update(Booking $booking, BookingStatus $target): Booking
    {
        if ($target === BookingStatus::CANCELLED) {
            throw ApiException::make(
                ApiErrorCode::INVALID_STATUS_TRANSITION,
                __('booking.invalid_transition', [
                    'from' => $booking->status->label(),
                    'to' => $target->label(),
                ]),
                details: [
                    'from' => $booking->status->value,
                    'to' => $target->value,
                    'expected' => $booking->status->next()?->value,
                ],
                http: 409,
            );
        }

        if ($target === BookingStatus::NO_SHOW) {
            return $this->advance($booking, $target, [
                'cancel_reason' => null,
                'cancelled_at' => $this->now($booking),
            ]);
        }

        return match ($target) {
            BookingStatus::ARRIVED => $this->arrive($booking),
            BookingStatus::WITH_DOCTOR => $this->callIn($booking),
            BookingStatus::DONE => $this->complete($booking),
            default => $this->advance($booking, $target, []),
        };
    }

    /**
     * Cancelling frees the slot immediately — that is what makes rebooking
     * after a postponement possible.
     */
    public function cancel(Booking $booking, CancelReason $reason): Booking
    {
        if (! $booking->canBeCancelled()) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_CANCELLABLE,
                __('booking.not_cancellable', ['status' => $booking->status->label()]),
                details: ['status' => $booking->status->value],
            );
        }

        $booking->update([
            'status' => BookingStatus::CANCELLED,
            'cancel_reason' => $reason,
            'cancelled_at' => $this->now($booking),
        ]);

        return $booking->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function advance(Booking $booking, BookingStatus $target, array $attributes): Booking
    {
        if (! $booking->status->canAdvanceTo($target)) {
            throw ApiException::make(
                ApiErrorCode::INVALID_STATUS_TRANSITION,
                __('booking.invalid_transition', [
                    'from' => $booking->status->label(),
                    'to' => $target->label(),
                ]),
                details: [
                    'from' => $booking->status->value,
                    'to' => $target->value,
                    'expected' => $booking->status->next()?->value,
                ],
                http: 409,
            );
        }

        $booking->update($attributes + ['status' => $target]);

        return $booking->refresh();
    }

    /**
     * Stamped in the clinic's timezone, matching how booking times are stored.
     */
    private function now(Booking $booking): Carbon
    {
        return Carbon::now($booking->clinic?->timezone ?? config('app.timezone'));
    }
}
