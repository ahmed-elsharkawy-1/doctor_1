<?php

namespace App\Enums;

/**
 * Booking lifecycle — see SPEC §5.4.
 *
 *   booked -> arrived -> with_doctor -> done
 *      \_________/ -> cancelled | no_show
 */
enum BookingStatus: string
{
    case BOOKED = 'booked';
    case ARRIVED = 'arrived';
    case WITH_DOCTOR = 'with_doctor';
    case DONE = 'done';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';

    public function label(): string
    {
        return __('booking.status.'.$this->value);
    }

    /**
     * The status this one advances to, or null if it is terminal.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::BOOKED => self::ARRIVED,
            self::ARRIVED => self::WITH_DOCTOR,
            self::WITH_DOCTOR => self::DONE,
            self::DONE, self::CANCELLED, self::NO_SHOW => null,
        };
    }

    public function canAdvanceTo(self $target): bool
    {
        if ($target === self::NO_SHOW) {
            return in_array($this, [self::BOOKED, self::ARRIVED], true);
        }

        return $this->next() === $target;
    }

    /**
     * Cancellation is allowed until the visit is completed. No-show remains a
     * separate status transition and is not valid once the patient is with the
     * doctor.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::BOOKED, self::ARRIVED, self::WITH_DOCTOR], true);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::BOOKED, self::ARRIVED], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::DONE, self::CANCELLED, self::NO_SHOW], true);
    }

    /**
     * Statuses that hold a slot.
     *
     * A slot is a claim on the doctor's *future* time, so a booking holds one
     * only until the visit starts. Once the patient is with the doctor that
     * claim is being met now rather than later, and the scheduled window is
     * free — which matters when a clinic runs ahead of itself and sees a 5pm
     * patient at 3pm. Before that it must still hold: an arrived patient is
     * waiting, and their visit is demand nobody has served yet. Free their
     * slot and a busy evening can be booked twice over.
     *
     * Cancelled and no-show hold nothing; there is no visit to come.
     *
     * @return list<self>
     */
    public static function occupyingSlot(): array
    {
        return [self::BOOKED, self::ARRIVED];
    }

    /**
     * Statuses that count as a visit to this clinic — everything except a
     * booking that was cancelled or never turned up.
     *
     * Not the same question as occupyingSlot(), though the two sets matched
     * until a finished visit stopped holding its slot. This one is about a
     * patient's history: someone booked yesterday and seen today is not a new
     * patient, and a visit that happened two hours early still happened.
     *
     * @return list<self>
     */
    public static function countsAsVisit(): array
    {
        return [self::BOOKED, self::ARRIVED, self::WITH_DOCTOR, self::DONE];
    }

    /**
     * Statuses still in play for today's queue — the postpone candidates.
     *
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::BOOKED, self::ARRIVED];
    }

    /**
     * Statuses that still hold a place in today's queue — everyone the patient
     * ahead of them has yet to be finished with. Wider than `pending()`, which
     * excludes the patient currently with the doctor.
     *
     * @return list<self>
     */
    public static function inQueue(): array
    {
        return [self::BOOKED, self::ARRIVED, self::WITH_DOCTOR];
    }

    /**
     * @return array<string, string> value => label, for Filament selects
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
