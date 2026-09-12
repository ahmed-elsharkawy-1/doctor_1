<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\VisitType;
use App\Services\V1\Booking\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * A slot grid follows the visit, not the clock.
 *
 * `slot_step_minutes` used to set how often a start time was offered no matter
 * how long the visit was, so a 20-minute consultation on a 10-minute step came
 * back as 13:00, 13:10, 13:20 — each overlapping the last. Left empty it now
 * follows the visit type's own duration; set, it still gives rolling starts.
 */
class SlotGridFollowsVisitLengthTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->delete();
        $schedule->periods()->create(['start_time' => '13:00', 'end_time' => '15:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function visitType(int $minutes): VisitType
    {
        return VisitType::create([
            'clinic_id' => $this->clinic->id,
            'name' => "زيارة {$minutes}",
            'duration_minutes' => $minutes,
            'price' => 100,
            'is_active' => true,
        ]);
    }

    /**
     * @return list<string> "start-end" for each offered slot
     */
    private function grid(VisitType $visitType): array
    {
        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse('2026-09-12', $this->clinic->timezone),
            $visitType,
        );

        return array_map(
            fn ($slot) => $slot->startAt->format('H:i').'-'.$slot->endAt->format('H:i'),
            $availability->slots,
        );
    }

    public function test_a_twenty_minute_visit_is_offered_every_twenty_minutes(): void
    {
        $grid = $this->grid($this->visitType(20));

        $this->assertSame(
            ['13:00-13:20', '13:20-13:40', '13:40-14:00', '14:00-14:20', '14:20-14:40', '14:40-15:00'],
            $grid,
        );
    }

    public function test_a_ten_minute_visit_is_offered_every_ten_minutes(): void
    {
        $grid = $this->grid($this->visitType(10));

        $this->assertSame('13:00-13:10', $grid[0]);
        $this->assertSame('13:10-13:20', $grid[1]);
        $this->assertCount(12, $grid);
    }

    public function test_a_fifteen_minute_visit_is_offered_every_fifteen_minutes(): void
    {
        $grid = $this->grid($this->visitType(15));

        $this->assertSame(['13:00-13:15', '13:15-13:30', '13:30-13:45', '13:45-14:00',
            '14:00-14:15', '14:15-14:30', '14:30-14:45', '14:45-15:00'], $grid);
    }

    /**
     * The defect this replaced: no offered slot may start before the one
     * before it has finished.
     */
    public function test_no_two_offered_slots_overlap(): void
    {
        foreach ([10, 15, 20, 25] as $minutes) {
            $grid = $this->grid($this->visitType($minutes));

            foreach ($grid as $i => $slot) {
                if ($i === 0) {
                    continue;
                }

                [, $previousEnd] = explode('-', $grid[$i - 1]);
                [$start] = explode('-', $slot);

                $this->assertGreaterThanOrEqual(
                    $previousEnd,
                    $start,
                    "A {$minutes}-minute visit offered {$slot} before {$grid[$i - 1]} had finished.",
                );
            }
        }
    }

    /**
     * A clinic that wants a cancellation at 13:10 refillable at 13:10 can
     * still say so, and gets exactly the previous behaviour.
     */
    public function test_a_clinic_can_still_ask_for_rolling_start_times(): void
    {
        $this->clinic->update(['slot_step_minutes' => 10]);

        $grid = $this->grid($this->visitType(20));

        $this->assertSame(['13:00-13:20', '13:10-13:30', '13:20-13:40'], array_slice($grid, 0, 3));
    }

    /**
     * Whatever the grid, the doctor is never in two places at once.
     */
    public function test_a_booking_still_blocks_every_slot_it_touches(): void
    {
        $twenty = $this->visitType(20);

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-12 13:20', $this->clinic->timezone), 20)
            ->create(['visit_type_id' => $twenty->id]);

        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse('2026-09-12', $this->clinic->timezone),
            $this->visitType(15),
        );

        foreach ($availability->slots as $slot) {
            $overlaps = $slot->startAt->format('H:i') < '13:40'
                && $slot->endAt->format('H:i') > '13:20';

            $this->assertSame(
                ! $overlaps,
                $slot->isAvailable,
                'Slot '.$slot->startAt->format('H:i').'-'.$slot->endAt->format('H:i').' judged wrongly.',
            );
        }
    }
}
