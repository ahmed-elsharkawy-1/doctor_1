<?php

namespace App\Enums;

/**
 * Booking lifecycle — see SPEC §5.4.
 *
 *   booked -> arrived -> with_doctor -> done
 *      \_________/ -> cancelled
 */
enum BookingStatus: string
{
    case BOOKED = 'booked';
    case ARRIVED = 'arrived';
    case WITH_DOCTOR = 'with_doctor';
    case DONE = 'done';
    case CANCELLED = 'cancelled';

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
            self::DONE, self::CANCELLED => null,
        };
    }

    public function canAdvanceTo(self $target): bool
    {
        return $this->next() === $target;
    }

    /**
     * Cancellation is allowed from `booked` and `arrived` only. A patient who
     * is already with the doctor must be completed.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::BOOKED, self::ARRIVED], true);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::BOOKED, self::ARRIVED], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::DONE, self::CANCELLED], true);
    }

    /**
     * Statuses that occupy a time slot. Cancelled bookings free their slot.
     *
     * @return list<self>
     */
    public static function occupyingSlot(): array
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
     * Sort weight for the queue list — see SPEC §4.2. Lower sorts first.
     */
    public function queueWeight(): int
    {
        return match ($this) {
            self::WITH_DOCTOR => 0,
            self::ARRIVED => 1,
            self::BOOKED => 2,
            self::DONE => 3,
            self::CANCELLED => 4,
        };
    }

    /**
     * Only patients physically in the clinic hold a queue position.
     */
    public function holdsQueuePosition(): bool
    {
        return in_array($this, [self::WITH_DOCTOR, self::ARRIVED], true);
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
