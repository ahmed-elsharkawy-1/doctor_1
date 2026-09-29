<?php

namespace Tests\Feature\SelfBooking;

use App\Services\V1\Booking\Slot;
use App\Services\V1\Booking\SlotGrouper;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Folding a day's slots into a few collapsed stretches.
 *
 * Presentation only, so nothing here asserts anything about booking. What it
 * does assert is that the grouping never misleads: it must not span hours the
 * doctor does not work, must not lose a slot, and must not leave a stretch
 * holding a single slot because the cap happened to fall there.
 */
class SlotGroupingTest extends TestCase
{
    private const DAY = '2026-09-03';

    private function slots(string $from, int $count, int $minutes = 20, array $taken = []): array
    {
        $at = Carbon::parse(self::DAY.' '.$from);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $start = $at->copy()->addMinutes($i * $minutes);
            $out[] = new Slot($start, $start->copy()->addMinutes($minutes), ! in_array($i, $taken, true));
        }

        return $out;
    }

    private function period(string $from, string $to): array
    {
        return [Carbon::parse(self::DAY.' '.$from), Carbon::parse(self::DAY.' '.$to)];
    }

    private function grouper(): SlotGrouper
    {
        return new SlotGrouper;
    }

    /*
    |--------------------------------------------------------------------------
    | When to group at all
    |--------------------------------------------------------------------------
    */

    /** A short day reads at a glance; grouping would only add a tap. */
    public function test_a_short_day_is_not_grouped(): void
    {
        config(['clinic.self_booking.slot_groups.min_to_group' => 10]);

        $this->assertSame([], $this->grouper()->group(
            $this->slots('09:00', 6),
            [$this->period('09:00', '11:00')],
        ));
    }

    public function test_a_long_day_is_grouped(): void
    {
        config(['clinic.self_booking.slot_groups.min_to_group' => 10]);

        $this->assertNotSame([], $this->grouper()->group(
            $this->slots('09:00', 12),
            [$this->period('09:00', '13:00')],
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | The doctor's own hours
    |--------------------------------------------------------------------------
    */

    /**
     * The rule that matters most. A group spanning the gap between a morning
     * and an evening would claim hours the doctor does not work.
     */
    public function test_a_group_never_spans_two_of_the_doctors_periods(): void
    {
        config([
            'clinic.self_booking.slot_groups.min_to_group' => 4,
            'clinic.self_booking.slot_groups.max_per_group' => 9,
        ]);

        $groups = $this->grouper()->group(
            array_merge($this->slots('09:00', 3), $this->slots('17:00', 3)),
            [$this->period('09:00', '10:00'), $this->period('17:00', '18:00')],
        );

        $this->assertCount(2, $groups);
        $this->assertSame('09:00', $groups[0]->startAt()->format('H:i'));
        $this->assertSame('17:00', $groups[1]->startAt()->format('H:i'));
    }

    /** Two moderate stretches stay exactly two — the doctor's own boundaries. */
    public function test_periods_short_enough_become_one_group_each(): void
    {
        config([
            'clinic.self_booking.slot_groups.min_to_group' => 4,
            'clinic.self_booking.slot_groups.max_per_group' => 9,
        ]);

        $groups = $this->grouper()->group(
            array_merge($this->slots('09:00', 6), $this->slots('17:00', 6)),
            [$this->period('09:00', '12:00'), $this->period('17:00', '20:00')],
        );

        $this->assertCount(2, $groups);
        $this->assertSame(6, $groups[0]->total());
        $this->assertSame(6, $groups[1]->total());
    }

    /*
    |--------------------------------------------------------------------------
    | Splitting
    |--------------------------------------------------------------------------
    */

    /** Nine and one looks like a mistake; five and five is the same day. */
    public function test_a_long_period_splits_evenly_rather_than_to_the_cap(): void
    {
        config([
            'clinic.self_booking.slot_groups.min_to_group' => 4,
            'clinic.self_booking.slot_groups.max_per_group' => 9,
        ]);

        $groups = $this->grouper()->group(
            $this->slots('09:00', 10, 10),
            [$this->period('09:00', '11:00')],
        );

        $this->assertCount(2, $groups);
        $this->assertSame([5, 5], [$groups[0]->total(), $groups[1]->total()]);
    }

    public function test_no_group_exceeds_the_cap(): void
    {
        config([
            'clinic.self_booking.slot_groups.min_to_group' => 4,
            'clinic.self_booking.slot_groups.max_per_group' => 9,
        ]);

        foreach ($this->grouper()->group($this->slots('09:00', 48, 10), [$this->period('09:00', '17:00')]) as $group) {
            $this->assertLessThanOrEqual(9, $group->total());
        }
    }

    /** Every slot survives the fold, in order. */
    public function test_grouping_loses_nothing(): void
    {
        config([
            'clinic.self_booking.slot_groups.min_to_group' => 4,
            'clinic.self_booking.slot_groups.max_per_group' => 9,
        ]);

        $slots = array_merge($this->slots('09:00', 14, 10), $this->slots('17:00', 9, 10));
        $groups = $this->grouper()->group($slots, [$this->period('09:00', '12:00'), $this->period('17:00', '19:00')]);

        $regrouped = array_merge(...array_map(static fn ($g): array => $g->slots, $groups));

        $this->assertCount(count($slots), $regrouped);
        $this->assertSame(
            array_map(static fn (Slot $s): string => $s->startAt->format('H:i'), $slots),
            array_map(static fn (Slot $s): string => $s->startAt->format('H:i'), $regrouped),
        );
    }

    /**
     * A schedule edited between the grid being built and this running would
     * otherwise make a bookable slot vanish from the page.
     */
    public function test_a_slot_outside_every_period_is_kept(): void
    {
        config([
            'clinic.self_booking.slot_groups.min_to_group' => 2,
            'clinic.self_booking.slot_groups.max_per_group' => 9,
        ]);

        $groups = $this->grouper()->group(
            array_merge($this->slots('09:00', 2), $this->slots('22:00', 1)),
            [$this->period('09:00', '10:00')],
        );

        $kept = array_merge(...array_map(static fn ($g): array => $g->slots, $groups));

        $this->assertCount(3, $kept);
    }

    /*
    |--------------------------------------------------------------------------
    | How full a stretch reads
    |--------------------------------------------------------------------------
    */

    public static function levels(): array
    {
        return [
            'nothing taken' => [[], 'many'],
            'half taken' => [[0, 1, 2, 3, 4], 'some'],
            'nearly all taken' => [[0, 1, 2, 3, 4, 5, 6, 7], 'few'],
            'all taken' => [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9], 'none'],
        ];
    }

    #[DataProvider('levels')]
    public function test_a_stretch_says_how_full_it_is(array $taken, string $expected): void
    {
        config([
            'clinic.self_booking.slot_groups.min_to_group' => 4,
            'clinic.self_booking.slot_groups.max_per_group' => 10,
            'clinic.self_booking.slot_groups.busy_at' => 0.6,
            'clinic.self_booking.slot_groups.scarce_at' => 0.3,
        ]);

        $groups = $this->grouper()->group(
            $this->slots('09:00', 10, 10, $taken),
            [$this->period('09:00', '11:00')],
        );

        $this->assertSame($expected, $groups[0]->level());
    }
}
