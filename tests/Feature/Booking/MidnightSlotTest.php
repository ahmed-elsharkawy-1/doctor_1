<?php

namespace Tests\Feature\Booking;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\DayOfWeek;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * A shift that ends "at midnight".
 *
 * A period cannot end at 24:00 — times are HH:MM within one day — so 23:59 is
 * how the app says "to the end of the day", and the last visit before
 * midnight must still be offered.
 */
class MidnightSlotTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-08-10';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo', 'slot_step_minutes' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<string> */
    private function starts(string $end): array
    {
        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->periods()->delete();
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '23:00', 'end_time' => $end]);

        $visitType = $this->clinic->visitTypes()->first();
        $visitType->update(['duration_minutes' => 10]);

        $availability = app(SlotAvailabilityService::class)
            ->for($this->clinic->fresh(), Carbon::parse(self::DAY, 'Africa/Cairo'), $visitType->fresh());

        return collect($availability->slots)->map(fn ($slot) => $slot->startAt->format('H:i'))->all();
    }

    public function test_a_period_ending_at_2359_runs_to_midnight(): void
    {
        $this->assertSame(['23:00', '23:10', '23:20', '23:30', '23:40', '23:50'], $this->starts('23:59'));
    }

    public function test_any_other_end_is_taken_literally(): void
    {
        $this->assertSame(['23:00', '23:10', '23:20', '23:30', '23:40'], $this->starts('23:55'));
    }

    public function test_the_last_slot_before_midnight_can_be_booked(): void
    {
        $this->starts('23:59');
        $visitType = $this->clinic->visitTypes()->first();

        $booking = app(BookingService::class)->create($this->clinic, BookingData::fromArray([
            'patient_name' => 'مريضة',
            'phone' => '01012345678',
            'visit_type_id' => $visitType->id,
            'date' => self::DAY,
            'start_time' => '23:50',
        ]), $this->owner);

        $this->assertSame(self::DAY, $booking->visit_date->toDateString());
        $this->assertSame('23:50', $booking->start_at->format('H:i'));
    }
}
