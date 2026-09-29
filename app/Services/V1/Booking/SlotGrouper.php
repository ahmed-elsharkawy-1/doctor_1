<?php

namespace App\Services\V1\Booking;

use Illuminate\Support\Carbon;

/**
 * Folds a day's slots into a few collapsed stretches.
 *
 * A pure function over slots that already exist. It reads no database, writes
 * nothing, and decides nothing about what may be booked — remove it and the
 * page renders the flat list it rendered before.
 *
 * Two rules shape the result:
 *
 * **A group never crosses one of the doctor's own periods.** A clinic working
 * 9–2 and 5–9 has two natural stretches, and a group spanning the gap would
 * claim hours she does not work. Where a period is short enough, it becomes
 * exactly one group — which is why the common morning-and-evening day reads
 * as two rows with her real boundaries.
 *
 * **A period too long for one group splits evenly, not to the cap.** Ten
 * slots with a cap of nine would otherwise give nine and one, and a stretch
 * holding a single slot looks like a mistake. Five and five is the same
 * information, better presented.
 */
class SlotGrouper
{
    /**
     * @param  list<Slot>  $slots  the day's slots, in order, taken ones included
     * @param  list<array{0: Carbon, 1: Carbon}>  $periods  the doctor's own opening stretches
     * @return list<SlotGroup> empty when the day is short enough to show flat
     */
    public function group(array $slots, array $periods): array
    {
        if (count($slots) < $this->minimumToGroup()) {
            return [];
        }

        $groups = [];
        $index = 1;

        foreach ($this->byPeriod($slots, $periods) as $inPeriod) {
            foreach ($this->split($inPeriod) as $chunk) {
                $groups[] = new SlotGroup($index++, $chunk);
            }
        }

        return $groups;
    }

    /**
     * Slots that fall in no period at all are kept rather than dropped.
     *
     * They should not exist — the grid is built from the periods — but a
     * schedule edited between the grid being built and this running would
     * otherwise make slots vanish from the page while still being bookable.
     * Losing a bookable slot silently is worse than an odd-looking group.
     *
     * @param  list<Slot>  $slots
     * @param  list<array{0: Carbon, 1: Carbon}>  $periods
     * @return list<list<Slot>>
     */
    private function byPeriod(array $slots, array $periods): array
    {
        if ($periods === []) {
            return [$slots];
        }

        $buckets = [];
        $orphans = [];

        foreach ($slots as $slot) {
            $placed = false;

            foreach ($periods as $i => [$opens, $closes]) {
                if ($slot->startAt >= $opens && $slot->startAt < $closes) {
                    $buckets[$i][] = $slot;
                    $placed = true;

                    break;
                }
            }

            if (! $placed) {
                $orphans[] = $slot;
            }
        }

        ksort($buckets);

        if ($orphans !== []) {
            $buckets[] = $orphans;
        }

        return array_values($buckets);
    }

    /**
     * Splits one period's slots into as few even chunks as the cap allows.
     *
     * @param  list<Slot>  $slots
     * @return list<list<Slot>>
     */
    private function split(array $slots): array
    {
        $total = count($slots);
        $cap = $this->maxPerGroup();

        if ($total <= $cap) {
            return [$slots];
        }

        $chunks = (int) ceil($total / $cap);
        $base = intdiv($total, $chunks);
        // The first few carry the remainder, so sizes differ by at most one.
        $wider = $total % $chunks;

        $out = [];
        $offset = 0;

        for ($i = 0; $i < $chunks; $i++) {
            $size = $base + ($i < $wider ? 1 : 0);
            $out[] = array_slice($slots, $offset, $size);
            $offset += $size;
        }

        return $out;
    }

    private function maxPerGroup(): int
    {
        return max(1, (int) config('clinic.self_booking.slot_groups.max_per_group', 9));
    }

    private function minimumToGroup(): int
    {
        return max(1, (int) config('clinic.self_booking.slot_groups.min_to_group', 10));
    }
}
