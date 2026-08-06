<?php

namespace App\Services\V1\Booking;

use App\Models\VisitType;
use Illuminate\Support\Carbon;

/**
 * The computed picture of one day for one visit type.
 */
final class DayAvailability
{
    /**
     * @param  list<Slot>  $slots
     */
    private function __construct(
        public readonly Carbon $date,
        public readonly VisitType $visitType,
        public readonly bool $isOpen,
        public readonly ?ClosedReason $closedReason,
        public readonly array $slots,
    ) {}

    /**
     * @param  list<Slot>  $slots
     */
    public static function open(Carbon $date, VisitType $visitType, array $slots): self
    {
        return new self($date, $visitType, true, null, $slots);
    }

    public static function closed(Carbon $date, VisitType $visitType, ClosedReason $reason): self
    {
        return new self($date, $visitType, false, $reason, []);
    }

    public function hasAvailableSlot(): bool
    {
        foreach ($this->slots as $slot) {
            if ($slot->isAvailable) {
                return true;
            }
        }

        return false;
    }

    public function availableCount(): int
    {
        return count(array_filter($this->slots, static fn (Slot $slot): bool => $slot->isAvailable));
    }
}
