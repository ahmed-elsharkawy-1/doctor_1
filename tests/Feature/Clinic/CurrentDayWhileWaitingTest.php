<?php

namespace Tests\Feature\Clinic;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\V1\Queue\QueuePositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * After midnight, the evening stays "today" while it still has patients
 * waiting — a delay can push a shift past its own period, and those patients
 * must not vanish from the secretary's list and their tracking pages at 00:00.
 * Never past the end of the clinic day.
 */
class CurrentDayWhileWaitingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A Monday-evening booking whose real time is $at (it may fall after midnight). */
    private function mondayBooking(string $at, BookingStatus $status = BookingStatus::BOOKED): Booking
    {
        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse($at, 'Africa/Cairo'), 10)
            ->create(['status' => $status, 'visit_date' => '2026-08-10']);
    }

    private function now(string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at, 'Africa/Cairo'));
    }

    public function test_the_evening_stays_today_while_a_delayed_patient_is_still_waiting(): void
    {
        $this->mondayBooking('2026-08-11 00:30');
        $this->now('2026-08-11 00:15');

        $this->assertSame('2026-08-10', $this->clinic->currentDay()->toDateString());
    }

    public function test_a_patient_in_the_clinic_or_with_the_doctor_holds_the_day_too(): void
    {
        $this->mondayBooking('2026-08-11 00:30', BookingStatus::ARRIVED);
        $this->now('2026-08-11 00:50');
        $this->assertSame('2026-08-10', $this->clinic->currentDay()->toDateString());

        Booking::query()->update(['status' => BookingStatus::WITH_DOCTOR]);
        $this->assertSame('2026-08-10', $this->clinic->currentDay()->toDateString());
    }

    /** Once everyone is seen, the night moves on as usual. */
    public function test_when_nobody_is_waiting_today_is_the_calendar_day(): void
    {
        $this->mondayBooking('2026-08-11 00:30', BookingStatus::DONE);
        $this->mondayBooking('2026-08-10 22:00', BookingStatus::NO_SHOW);
        $this->now('2026-08-11 00:45');

        $this->assertSame('2026-08-11', $this->clinic->currentDay()->toDateString());
    }

    /** A patient simply left unmarked hours ago does not hold the day open. */
    public function test_a_patient_left_unmarked_long_ago_does_not_hold_the_day(): void
    {
        $this->mondayBooking('2026-08-10 21:00');
        $this->now('2026-08-11 00:30');

        $this->assertSame('2026-08-11', $this->clinic->currentDay()->toDateString());
    }

    /** The end of the clinic day is the hard limit, whatever is still waiting. */
    public function test_the_day_never_runs_past_the_end_of_the_clinic_day(): void
    {
        $this->mondayBooking('2026-08-11 05:55');

        $this->now('2026-08-11 05:59');
        $this->assertSame('2026-08-10', $this->clinic->currentDay()->toDateString());

        $this->now('2026-08-11 06:00');
        $this->assertSame('2026-08-11', $this->clinic->currentDay()->toDateString());
    }

    /** Before midnight nothing changes. */
    public function test_the_evening_itself_is_unaffected(): void
    {
        $this->mondayBooking('2026-08-10 22:00');
        $this->now('2026-08-10 21:00');

        $this->assertSame('2026-08-10', $this->clinic->currentDay()->toDateString());
    }

    /** What it is for: the waiting patient's tracking page still shows their place. */
    public function test_a_delayed_patient_keeps_their_place_on_the_tracking_page_after_midnight(): void
    {
        $this->mondayBooking('2026-08-11 00:10');
        $mine = $this->mondayBooking('2026-08-11 00:20');
        $this->now('2026-08-11 00:05');

        $position = app(QueuePositionService::class)->for($mine->fresh());

        $this->assertNotNull($position);
        $this->assertSame(1, $position->ahead);
    }
}
