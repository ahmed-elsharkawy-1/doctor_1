<?php

namespace App\Services\V1\Queue;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Events\SlotsChanged;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Patients\OnlineBookingService;
use Illuminate\Support\Carbon;

/**
 * Moves a booking through the six-status lifecycle — SPEC §5.4.
 *
 *   booked -> arrived -> with_doctor -> done
 *   booked/arrived -> no_show
 *
 * Each clinical step stamps its own timestamp for later wait-time reporting.
 *
 * Which day a booking may move on: its own clinic day, or a past one within
 * clinic.status.past_correction_days — a night the secretary left unfinished
 * — but never a future one. A past-day correction records no time it does
 * not know (it is hours or days late) and sends the patient nothing.
 */
class BookingStatusService
{
    public function __construct(
        private readonly WhatsAppMessagingService $messaging,
        private readonly OnlineBookingService $onlineBooking,
    ) {}

    public function arrive(Booking $booking): Booking
    {
        // A correction made the morning after does not know when they came:
        // "now" would read as a 4 a.m. arrival and skew waiting times.
        if ($this->isPastDay($booking)) {
            return $this->advance($booking, BookingStatus::ARRIVED, []);
        }

        $now = $this->now($booking);

        return $this->advance($booking, BookingStatus::ARRIVED, [
            'arrived_at' => $now,
            'queue_entered_at' => $booking->queue_entered_at ?? $now,
        ]);
    }

    public function callIn(Booking $booking): Booking
    {
        return $this->advance(
            $booking,
            BookingStatus::WITH_DOCTOR,
            $this->isPastDay($booking) ? [] : ['called_in_at' => $this->now($booking)],
        );
    }

    public function complete(Booking $booking): Booking
    {
        $pastDay = $this->isPastDay($booking);

        $booking = $this->advance($booking, BookingStatus::DONE, [
            'completed_at' => $pastDay ? $this->finishedAt($booking) : $this->now($booking),
        ]);

        // Thanks the patient and carries the review link. Sent from here so it
        // reaches them whichever client marked the visit done, and after the
        // transition so a messaging problem can never block it. Not for a past
        // day: "thank you for your visit today" hours or days late is wrong,
        // and the end-of-day close sends nothing either.
        if ($booking->clinic !== null && ! $pastDay) {
            $this->messaging->sendVisitCompleted($booking->clinic, $booking);
        }

        return $booking;
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
            $booking = $this->advance($booking, $target, [
                'cancel_reason' => null,
                'cancelled_at' => $this->now($booking),
            ]);

            // A patient who keeps not turning up loses the public booking
            // page. After the transition, so the rule counts this one too.
            if ($booking->patient !== null) {
                $this->onlineBooking->checkAfterNoShow($booking->patient);
            }

            return $booking;
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

        // Cancelling is the one status change that gives a slot back, so it
        // is the only one anybody else's screen needs to hear about.
        SlotsChanged::dispatch($booking->clinic, $booking->visit_date->toDateString());

        return $booking->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function advance(Booking $booking, BookingStatus $target, array $attributes): Booking
    {
        // Never ahead of the visit's own day: a patient who comes early is
        // moved to today first, and their booking for that day is never
        // marked. Behind it, within the correction window, is a night left
        // unfinished, and may be put right.
        $today = $booking->clinic?->currentDay()->toDateString();
        $visitDay = $booking->visit_date->toDateString();

        if ($today !== null && $visitDay > $today) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_TODAY,
                __('booking.not_today', ['date' => $visitDay]),
                details: [
                    'visit_date' => $visitDay,
                    'today' => $today,
                ],
                http: 409,
            );
        }

        if ($today !== null && $visitDay < $this->oldestCorrectableDay($today)) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_TOO_OLD,
                __('booking.too_old', ['date' => $visitDay, 'days' => $this->correctionDays()]),
                details: [
                    'visit_date' => $visitDay,
                    'today' => $today,
                    'days' => $this->correctionDays(),
                ],
                http: 409,
            );
        }

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

    /** Whether the booking's day is behind the clinic's current day. */
    private function isPastDay(Booking $booking): bool
    {
        $today = $booking->clinic?->currentDay()->toDateString();

        return $today !== null && $booking->visit_date->toDateString() < $today;
    }

    /**
     * When a past visit finished, by the end-of-day close's own rule: its
     * call-in time plus the visit's length, else its booked end — never
     * later than now.
     */
    private function finishedAt(Booking $booking): Carbon
    {
        $now = $this->now($booking);
        $timezone = $booking->clinic?->timezone ?? config('app.timezone');

        $finished = match (true) {
            $booking->called_in_at !== null => Carbon::parse($booking->called_in_at->format('Y-m-d H:i:s'), $timezone)
                ->addMinutes(max(0, (int) $booking->duration_minutes)),
            $booking->end_at !== null => Carbon::parse($booking->end_at->format('Y-m-d H:i:s'), $timezone),
            default => $now,
        };

        return $finished->min($now);
    }

    private function oldestCorrectableDay(string $today): string
    {
        return Carbon::parse($today)->subDays($this->correctionDays())->toDateString();
    }

    private function correctionDays(): int
    {
        return max(0, (int) config('clinic.status.past_correction_days'));
    }

    /**
     * Stamped in the clinic's timezone, matching how booking times are stored.
     */
    private function now(Booking $booking): Carbon
    {
        return Carbon::now($booking->clinic?->timezone ?? config('app.timezone'));
    }
}
