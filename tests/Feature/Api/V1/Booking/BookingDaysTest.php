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

    private function calendar(array $query = []): array
    {
        return $this->getJson(route('api.v1.bookings.calendar', $query))->assertOk()->json('data');
    }

    public function test_it_returns_dense_days_for_the_requested_range(): void
    {
        $data = $this->calendar(['from' => '2026-08-08', 'to' => '2026-08-10']);

        $this->assertSame(['from' => '2026-08-08', 'to' => '2026-08-10'], $data['range']);
        $this->assertCount(3, $data['days']);
        $this->assertSame($this->saturday->toDateString(), $data['days'][0]['date']['value']);
        $this->assertTrue($data['days'][0]['is_today']);
    }

    public function test_it_marks_open_days_and_holidays(): void
    {
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => '2026-08-09',
        ]);

        $days = collect($this->calendar(['from' => '2026-08-08', 'to' => '2026-08-11'])['days'])
            ->keyBy(fn ($day) => $day['date']['value']);

        $this->assertTrue($days['2026-08-08']['is_open']);
        $this->assertFalse($days['2026-08-09']['is_open']);
        $this->assertTrue($days['2026-08-09']['is_holiday']);
        $this->assertFalse($days['2026-08-11']['is_open']);
    }

    public function test_counts_include_all_statuses_while_cards_can_be_filtered(): void
    {
        Booking::factory()->count(2)->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0))->create();

        Booking::factory()->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(10, 0))->done()->create();

        Booking::factory()->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(11, 0))->cancelled()->create();

        Booking::factory()->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(11, 30))->noShow()->create();

        $data = $this->calendar([
            'from' => '2026-08-08',
            'to' => '2026-08-08',
            'status' => 'booked',
        ]);

        $counts = $data['days'][0]['counts'];

        $this->assertSame(5, $counts['total']);
        $this->assertSame(2, $counts['booked']);
        $this->assertSame(1, $counts['done']);
        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(1, $counts['no_show']);
        $this->assertCount(2, $data['bookings']['2026-08-08']);
    }

    public function test_cards_are_sparse_date_keyed_and_ordered_by_appointment_time(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(10, 0))->create();

        Booking::factory()->forClinic($this->clinic)
            ->at($this->saturday->copy()->setTime(9, 0))->create();

        $bookings = $this->calendar(['from' => '2026-08-08', 'to' => '2026-08-10'])['bookings'];

        $this->assertSame(['2026-08-08'], array_keys($bookings));
        $this->assertSame('09:00', $bookings['2026-08-08'][0]['start_time']['value']);
        $this->assertArrayNotHasKey('queue_position', $bookings['2026-08-08'][0]);
        $this->assertSame('arrived', $bookings['2026-08-08'][0]['next_status']['value']);
    }

    public function test_another_clinics_bookings_are_not_counted(): void
    {
        Booking::factory()->forClinic($this->otherClinic())
            ->at($this->saturday->copy()->setTime(9, 0))->create();

        $data = $this->calendar(['from' => '2026-08-08', 'to' => '2026-08-08']);

        $this->assertSame(0, $data['days'][0]['counts']['total']);
        $this->assertSame([], $data['bookings']);
    }
}
