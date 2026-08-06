<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\ClinicHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class BookingDaysTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Carbon $saturday;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->saturday = Carbon::parse('2026-08-08');

        foreach ([DayOfWeek::SATURDAY, DayOfWeek::SUNDAY, DayOfWeek::MONDAY] as $day) {
            $schedule = $this->clinic->scheduleFor($day);
            $schedule->update(['is_open' => true]);
            $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '12:00']);
        }

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function days(): array
    {
        return $this->getJson(route('api.v1.booking-days'))->assertOk()->json('data.days');
    }

    public function test_it_returns_one_entry_per_day_of_the_window(): void
    {
        $days = $this->days();

        $this->assertCount($this->clinic->booking_window_days, $days);
        $this->assertSame($this->saturday->toDateString(), $days[0]['date']['value']);
        $this->assertTrue($days[0]['is_today']);
    }

    public function test_the_window_follows_the_clinic_setting(): void
    {
        $this->clinic->update(['booking_window_days' => 3]);

        $this->assertCount(3, $this->days());
    }

    public function test_it_marks_which_days_the_clinic_works(): void
    {
        $days = collect($this->days())->keyBy(fn ($day) => $day['date']['value']);

        $this->assertTrue($days['2026-08-08']['is_open']);   // Saturday
        $this->assertTrue($days['2026-08-09']['is_open']);   // Sunday
        $this->assertTrue($days['2026-08-10']['is_open']);   // Monday
        $this->assertFalse($days['2026-08-11']['is_open']);  // Tuesday
    }

    public function test_a_holiday_closes_an_otherwise_open_day(): void
    {
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => '2026-08-09',
        ]);

        $days = collect($this->days())->keyBy(fn ($day) => $day['date']['value']);

        $this->assertFalse($days['2026-08-09']['is_open']);
        $this->assertTrue($days['2026-08-09']['is_holiday']);
    }

    public function test_it_labels_days_saturday_first(): void
    {
        $days = $this->days();

        $this->assertSame(0, $days[0]['day_of_week']['value']);
        $this->assertSame('السبت', $days[0]['day_of_week']['display']);
        $this->assertSame(8, $days[0]['day_number']);
    }

    public function test_it_counts_bookings_and_those_not_yet_finished(): void
    {
        Booking::factory()->count(2)->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0))->create();

        Booking::factory()->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(10, 0))->done()->create();

        Booking::factory()->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(11, 0))->cancelled()->create();

        $today = collect($this->days())->firstWhere('date.value', '2026-08-08');

        // Cancelled bookings are not counted at all.
        $this->assertSame(3, $today['bookings_count']);
        // "X لسه ماخلصوش" — booked and arrived only.
        $this->assertSame(2, $today['pending_count']);
    }

    public function test_another_clinics_bookings_are_not_counted(): void
    {
        Booking::factory()->forClinic($this->otherClinic())
            ->at($this->saturday->copy()->setTime(9, 0))->create();

        $today = collect($this->days())->firstWhere('date.value', '2026-08-08');

        $this->assertSame(0, $today['bookings_count']);
    }
}
