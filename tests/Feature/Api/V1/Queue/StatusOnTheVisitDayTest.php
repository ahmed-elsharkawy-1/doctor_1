<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\BookingStatus;
use App\Livewire\App\Queue;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * A booking's status moves only on its own day: a booking for tomorrow is
 * never marked arrived, in or done tonight. A no-show may be recorded after
 * the day, never before it.
 */
class StatusOnTheVisitDayTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo']);
        Carbon::setTestNow(Carbon::parse('2026-09-03 12:00:00', 'Africa/Cairo'));
        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bookingOn(string $date, array $attributes = []): Booking
    {
        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse($date.' 17:00', 'Africa/Cairo'))
            ->create($attributes);
    }

    private function move(Booking $booking, BookingStatus $to)
    {
        return $this->postJson(route('api.v1.bookings.status', $booking), ['to' => $to->value]);
    }

    public function test_tomorrows_booking_cannot_be_marked_arrived(): void
    {
        $booking = $this->bookingOn('2026-09-04');

        $this->move($booking, BookingStatus::ARRIVED)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BOOKING_NOT_TODAY')
            ->assertJsonPath('error.details.visit_date', '2026-09-04')
            ->assertJsonPath('error.details.today', '2026-09-03');

        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }

    public function test_tomorrows_booking_cannot_be_marked_no_show(): void
    {
        $booking = $this->bookingOn('2026-09-04');

        $this->move($booking, BookingStatus::NO_SHOW)->assertStatus(409);

        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }

    public function test_yesterdays_booking_cannot_be_marked_in_or_done(): void
    {
        $arrived = $this->bookingOn('2026-09-02', ['status' => BookingStatus::ARRIVED, 'arrived_at' => now()->subDay()]);

        $this->move($arrived, BookingStatus::WITH_DOCTOR)->assertStatus(409)->assertJsonPath('error.code', 'BOOKING_NOT_TODAY');

        $this->assertSame(BookingStatus::ARRIVED, $arrived->fresh()->status);
    }

    public function test_a_no_show_can_be_recorded_after_the_day(): void
    {
        $booking = $this->bookingOn('2026-09-02');

        $this->move($booking, BookingStatus::NO_SHOW)->assertOk();

        $this->assertSame(BookingStatus::NO_SHOW, $booking->fresh()->status);
    }

    public function test_todays_booking_moves_as_before(): void
    {
        $booking = $this->bookingOn('2026-09-03');

        $this->move($booking, BookingStatus::ARRIVED)->assertOk();
        $this->move($booking->fresh(), BookingStatus::WITH_DOCTOR)->assertOk();
        $this->move($booking->fresh(), BookingStatus::DONE)->assertOk();

        $this->assertSame(BookingStatus::DONE, $booking->fresh()->status);
    }

    public function test_after_midnight_last_nights_patients_can_still_be_finished(): void
    {
        // Still waiting from last night: the clinic day has not rolled over.
        $booking = $this->bookingOn('2026-09-03', ['status' => BookingStatus::WITH_DOCTOR, 'arrived_at' => now(), 'called_in_at' => now()]);
        $booking->update(['start_at' => Carbon::parse('2026-09-03 23:50', 'Africa/Cairo'), 'end_at' => Carbon::parse('2026-09-04 00:00', 'Africa/Cairo')]);
        Carbon::setTestNow(Carbon::parse('2026-09-04 00:20:00', 'Africa/Cairo'));

        $this->move($booking->fresh(), BookingStatus::DONE)->assertOk();
    }

    public function test_the_clinic_screen_refuses_it_too(): void
    {
        $booking = $this->bookingOn('2026-09-04');

        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->call('arrive', $booking->id);

        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }
}
