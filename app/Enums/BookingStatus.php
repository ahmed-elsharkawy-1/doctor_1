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
     * Statuses that occupy a time slot. Cancelled and no-show bookings free
     * their slot.
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
