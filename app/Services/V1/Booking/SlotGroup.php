<?php

namespace App\Services\V1\Booking;

use Illuminate\Support\Carbon;

/**
 * A stretch of the day, shown as one collapsed row.
 *
 * Presentation only. A group owns no rule about what may be booked — the
 * slots inside it are the same objects the availability service produced, and
 * a slot's own `isAvailable` remains the only thing that decides anything.
 */
final class SlotGroup
{
    /**
     * @param  int  $index  1-based, for "الفترة الأولى"
     * @param  list<Slot>  $slots
     */
    public function __construct(
        public readonly int $index,
        public readonly array $slots,
    ) {}

    public function startAt(): Carbon
    {
        return $this->slots[0]->startAt;
    }

    public function endAt(): Carbon
    {
        return $this->slots[array_key_last($this->slots)]->endAt;
    }

    public function total(): int
    {
        return count($this->slots);
    }

    public function freeCount(): int
    {
        return count(array_filter($this->slots, static fn (Slot $s): bool => $s->isAvailable));
    }

    public function hasAnythingFree(): bool
    {
        return $this->freeCount() > 0;
    }

    public function contains(string $time): bool
    {
        foreach ($this->slots as $slot) {
            if ($slot->startAt->format('H:i') === $time) {
                return true;
            }
        }

        return false;
    }

    /**
     * How full this stretch is, as one of four words.
     *
     * A proportion rather than a count: eight free out of ten reads as roomy
     * and eight out of forty does not, and the patient is deciding where to
     * look rather than counting.
     */
    public function level(): string
    {
        $free = $this->freeCount();

        if ($free === 0) {
            return 'none';
        }

        $ratio = $free / max(1, $this->total());

        return match (true) {
            $ratio >= (float) config('clinic.self_booking.slot_groups.busy_at') => 'many',
            $ratio >= (float) config('clinic.self_booking.slot_groups.scarce_at') => 'some',
            default => 'few',
        };
    }
}
