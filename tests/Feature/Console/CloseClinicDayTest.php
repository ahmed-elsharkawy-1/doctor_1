<?php

namespace Tests\Feature\Console;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class CloseClinicDayTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-09 00:30:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function yesterday(BookingStatus $status): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-08 09:00', 'Africa/Cairo'))
            ->create(['status' => $status]);
    }

    public function test_a_patient_who_never_arrived_becomes_a_no_show(): void
    {
        $booking = $this->yesterday(BookingStatus::BOOKED);

        $this->artisan('clinic:close-day')->assertSuccessful();

        $booking->refresh();

        $this->assertSame(BookingStatus::CANCELLED, $booking->status);
        $this->assertSame(CancelReason::NO_SHOW, $booking->cancel_reason);
        $this->assertNotNull($booking->cancelled_at);
    }

    /**
     * She was here, so calling it a no-show would be wrong — but the visit was
     * never completed, so it must not count as revenue either.
     */
    public function test_an_unfinished_visit_is_closed_as_incomplete(): void
    {
        $arrived = $this->yesterday(BookingStatus::ARRIVED);
        $inRoom = $this->yesterday(BookingStatus::WITH_DOCTOR);

        $this->artisan('clinic:close-day')->assertSuccessful();

        foreach ([$arrived, $inRoom] as $booking) {
            $booking->refresh();
            $this->assertSame(BookingStatus::CANCELLED, $booking->status);
            $this->assertSame(CancelReason::INCOMPLETE, $booking->cancel_reason);
        }
    }

    public function test_completed_visits_are_left_alone(): void
    {
        $done = $this->yesterday(BookingStatus::DONE);

        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertSame(BookingStatus::DONE, $done->refresh()->status);
    }

    public function test_already_cancelled_bookings_keep_their_reason(): void
    {
        $cancelled = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-08 09:00', 'Africa/Cairo'))
            ->cancelled(CancelReason::PATIENT_CANCELLED)
            ->create();

        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertSame(CancelReason::PATIENT_CANCELLED, $cancelled->refresh()->cancel_reason);
    }

    public function test_todays_bookings_are_never_touched(): void
    {
        $today = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-09 09:00', 'Africa/Cairo'))
            ->create();

        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertSame(BookingStatus::BOOKED, $today->refresh()->status);
    }

    public function test_future_bookings_are_never_touched(): void
    {
        $future = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-12 09:00', 'Africa/Cairo'))
            ->create();

        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertSame(BookingStatus::BOOKED, $future->refresh()->status);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $booking = $this->yesterday(BookingStatus::BOOKED);

        $this->artisan('clinic:close-day')->assertSuccessful();
        $firstPass = $booking->refresh()->cancelled_at;

        Carbon::setTestNow(Carbon::parse('2026-08-09 01:30:00', 'Africa/Cairo'));
        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertEquals($firstPass, $booking->refresh()->cancelled_at);
    }

    public function test_it_can_be_limited_to_one_clinic(): void
    {
        $mine = $this->yesterday(BookingStatus::BOOKED);

        $other = $this->otherClinic();
        $theirs = Booking::factory()
            ->forClinic($other)
            ->at(Carbon::parse('2026-08-08 09:00', 'Africa/Cairo'))
            ->create();

        $this->artisan('clinic:close-day', ['--clinic' => $other->id])->assertSuccessful();

        $this->assertSame(BookingStatus::BOOKED, $mine->refresh()->status);
        $this->assertSame(BookingStatus::CANCELLED, $theirs->refresh()->status);
    }

    public function test_an_inactive_clinic_is_skipped(): void
    {
        $booking = $this->yesterday(BookingStatus::BOOKED);
        $this->clinic->update(['is_active' => false]);

        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertSame(BookingStatus::BOOKED, $booking->refresh()->status);
    }

    /**
     * A clinic's day ends on its own clock, not the server's.
     */
    public function test_a_clinic_in_another_timezone_closes_on_its_own_day(): void
    {
        $tokyo = Clinic::factory()->create([
            'specialty_id' => Specialty::where('slug', 'general')->value('id'),
            'timezone' => 'Asia/Tokyo',
        ]);

        // 00:30 in Cairo is already 07:30 the same date in Tokyo, so a Tokyo
        // booking dated 9 August is still today there.
        $tokyoToday = Booking::factory()
            ->forClinic($tokyo)
            ->at(Carbon::parse('2026-08-09 09:00', 'Asia/Tokyo'))
            ->create();

        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertSame(BookingStatus::BOOKED, $tokyoToday->refresh()->status);
    }
}
