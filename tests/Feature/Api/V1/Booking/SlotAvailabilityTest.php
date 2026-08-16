<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\ClinicHoliday;
use App\Models\VisitType;
use App\Services\V1\Booking\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class SlotAvailabilityTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Carbon $saturday;

    protected function setUp(): void
    {
        parent::setUp();

        // Anchor the clock so "today" and the rolling window are deterministic.
        // 8 August 2026 is a Saturday.
        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->saturday = Carbon::parse('2026-08-08');

        $this->openDay(DayOfWeek::SATURDAY, [['09:00', '11:00']]);

        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $periods
     */
    private function openDay(DayOfWeek $day, array $periods): void
    {
        $schedule = $this->clinic->scheduleFor($day);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->delete();

        foreach ($periods as [$start, $end]) {
            $schedule->periods()->create(['start_time' => $start, 'end_time' => $end]);
        }
    }

    private function visitType(int $minutes): VisitType
    {
        return VisitType::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => "زيارة {$minutes}",
            'duration_minutes' => $minutes,
        ]);
    }

    private function slots(VisitType $visitType, ?Carbon $date = null): array
    {
        return $this->getJson(route('api.v1.slots', [
            'date' => ($date ?? $this->saturday)->toDateString(),
            'visit_type_id' => $visitType->id,
        ]))->assertOk()->json('data');
    }

    public function test_slots_step_by_the_clinics_configured_interval(): void
    {
        $data = $this->slots($this->visitType(20));

        $this->assertTrue($data['is_open']);
        $this->assertSame(
            ['09:00', '09:10', '09:20', '09:30', '09:40'],
            array_slice(array_column(array_column($data['slots'], 'start_time'), 'value'), 0, 5),
        );
    }

    /**
     * A 30-minute procedure must finish before the period ends — 10:40 would
     * run to 11:10, past close.
     */
    public function test_a_visit_that_would_not_finish_in_time_is_never_offered(): void
    {
        $starts = array_column(array_column($this->slots($this->visitType(30))['slots'], 'start_time'), 'value');

        $this->assertSame('10:30', end($starts));
        $this->assertNotContains('10:40', $starts);
    }

    public function test_a_longer_visit_type_yields_fewer_slots(): void
    {
        $short = count($this->slots($this->visitType(10))['slots']);
        $long = count($this->slots($this->visitType(60))['slots']);

        $this->assertGreaterThan($long, $short);
    }

    public function test_each_period_of_a_split_day_generates_its_own_slots(): void
    {
        $this->openDay(DayOfWeek::SATURDAY, [['13:00', '14:00'], ['17:00', '18:00']]);

        $starts = array_column(array_column($this->slots($this->visitType(30))['slots'], 'start_time'), 'value');

        $this->assertSame(['13:00', '13:10', '13:20', '13:30', '17:00', '17:10', '17:20', '17:30'], $starts);
    }

    /**
     * The whole point of SPEC §5.1: a booking of one length blocks candidate
     * slots of a different length that overlap it.
     */
    public function test_an_existing_booking_blocks_every_slot_it_overlaps(): void
    {
        $procedure = $this->visitType(30);

        Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0), 30)
            ->create();

        $checkup = $this->visitType(20);
        $slots = collect($this->slots($checkup)['slots'])->keyBy(fn ($s) => $s['start_time']['value']);

        // 09:00-09:30 is taken; a 20-minute visit starting 08:50..09:20 collides.
        $this->assertFalse($slots['09:00']['is_available']);
        $this->assertFalse($slots['09:10']['is_available']);
        $this->assertFalse($slots['09:20']['is_available']);
        // 09:30 starts exactly when the procedure ends — allowed.
        $this->assertTrue($slots['09:30']['is_available']);

        $this->assertSame($procedure->id, $procedure->id);
    }

    public function test_a_slot_starting_exactly_when_another_ends_is_free(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0), 20)
            ->create();

        $slots = collect($this->slots($this->visitType(20))['slots'])->keyBy(fn ($s) => $s['start_time']['value']);

        $this->assertFalse($slots['09:00']['is_available']);
        $this->assertTrue($slots['09:20']['is_available']);
    }

    public function test_a_cancelled_booking_frees_its_slot(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0), 20)
            ->cancelled()
            ->create();

        $slots = collect($this->slots($this->visitType(20))['slots'])->keyBy(fn ($s) => $s['start_time']['value']);

        $this->assertTrue($slots['09:00']['is_available']);
    }

    public function test_a_no_show_booking_frees_its_slot(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0), 20)
            ->noShow()
            ->create();

        $slots = collect($this->slots($this->visitType(20))['slots'])->keyBy(fn ($s) => $s['start_time']['value']);

        $this->assertTrue($slots['09:00']['is_available']);
    }

    public function test_past_starts_are_omitted_for_today_but_not_tomorrow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 09:35:00', 'Africa/Cairo'));
        $this->openDay(DayOfWeek::SUNDAY, [['09:00', '11:00']]);

        $todayStarts = array_column(array_column($this->slots($this->visitType(20))['slots'], 'start_time'), 'value');
        $tomorrowStarts = array_column(array_column($this->slots($this->visitType(20), $this->saturday->copy()->addDay())['slots'], 'start_time'), 'value');

        $this->assertSame('09:40', $todayStarts[0]);
        $this->assertNotContains('09:30', $todayStarts);
        $this->assertContains('09:00', $tomorrowStarts);
    }

    public function test_a_completed_booking_still_holds_its_slot(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0), 20)
            ->done()
            ->create();

        $slots = collect($this->slots($this->visitType(20))['slots'])->keyBy(fn ($s) => $s['start_time']['value']);

        $this->assertFalse($slots['09:00']['is_available']);
    }

    public function test_unavailable_slots_are_returned_not_hidden(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0), 20)
            ->create();

        $data = $this->slots($this->visitType(20));

        $starts = array_column(array_column($data['slots'], 'start_time'), 'value');

        $this->assertContains('09:00', $starts);
        $this->assertLessThan(count($data['slots']), $data['available_count']);
    }

    public function test_a_closed_weekday_returns_no_slots_with_a_reason(): void
    {
        $sunday = $this->saturday->copy()->addDay();

        $data = $this->slots($this->visitType(20), $sunday);

        $this->assertFalse($data['is_open']);
        $this->assertSame('weekly_closed', $data['closed_reason']['value']);
        $this->assertSame([], $data['slots']);
    }

    public function test_a_holiday_closes_an_otherwise_open_day(): void
    {
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => $this->saturday->toDateString(),
        ]);

        $data = $this->slots($this->visitType(20));

        $this->assertFalse($data['is_open']);
        $this->assertSame('holiday', $data['closed_reason']['value']);
    }

    public function test_a_date_past_the_booking_window_is_closed(): void
    {
        $beyond = $this->saturday->copy()->addDays($this->clinic->booking_window_days);

        $data = $this->slots($this->visitType(20), $beyond);

        $this->assertFalse($data['is_open']);
        $this->assertSame('outside_window', $data['closed_reason']['value']);
    }

    public function test_yesterday_is_closed(): void
    {
        $data = $this->slots($this->visitType(20), $this->saturday->copy()->subDay());

        $this->assertFalse($data['is_open']);
        $this->assertSame('outside_window', $data['closed_reason']['value']);
    }

    /**
     * The window is inclusive of today, so 7 days means today plus six.
     */
    public function test_the_window_covers_today_plus_the_configured_days(): void
    {
        $slots = app(SlotAvailabilityService::class);

        $this->assertTrue($slots->isWithinWindow($this->clinic, $this->saturday));
        $this->assertTrue($slots->isWithinWindow($this->clinic, $this->saturday->copy()->addDays(6)));
        $this->assertFalse($slots->isWithinWindow($this->clinic, $this->saturday->copy()->addDays(7)));
    }

    public function test_an_unknown_visit_type_is_rejected(): void
    {
        $this->getJson(route('api.v1.slots', [
            'date' => $this->saturday->toDateString(),
            'visit_type_id' => 9999,
        ]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VISIT_TYPE_NOT_FOUND');
    }

    public function test_another_clinics_bookings_do_not_block_this_ones_slots(): void
    {
        $other = $this->otherClinic();

        Booking::factory()
            ->forClinic($other)
            ->at($this->saturday->copy()->setTime(9, 0), 60)
            ->create();

        $slots = collect($this->slots($this->visitType(20))['slots'])->keyBy(fn ($s) => $s['start_time']['value']);

        $this->assertTrue($slots['09:00']['is_available']);
    }
}
