<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * When a visit was promised, and when it actually happened.
 *
 * A clinic that runs ahead of itself sees a 5pm patient at 3pm, and the two
 * are then different facts. `start_time` and `end_time` keep meaning the
 * promise and never move — the whole booking snapshots against them.
 * `seen_from` and `seen_to` carry what happened, and are null until it does.
 */
class ScheduledVersusActualTimeTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->delete();
        $schedule->periods()->create(['start_time' => '14:00', 'end_time' => '21:00']);

        // Promised 17:00.
        $this->booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-12 17:00', $this->clinic->timezone), 20)
            ->create();

        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function card(): array
    {
        return $this->getJson(route('api.v1.bookings.show', $this->booking))
            ->assertOk()
            ->json('data');
    }

    public function test_a_booking_nobody_has_seen_yet_carries_no_actual_time(): void
    {
        $data = $this->card();

        $this->assertSame('17:00', $data['start_time']['value']);
        $this->assertNull($data['seen_from']);
        $this->assertNull($data['seen_to']);
    }

    /**
     * Half of it is known while the patient is still in the room, and the
     * payload says exactly that rather than inventing an end.
     */
    public function test_a_visit_in_progress_reports_its_start_and_no_end(): void
    {
        $this->postJson(route('api.v1.bookings.status', $this->booking), ['to' => 'arrived'])->assertOk();
        $this->postJson(route('api.v1.bookings.status', $this->booking), ['to' => 'with_doctor'])->assertOk();

        $data = $this->card();

        $this->assertSame('17:00', $data['start_time']['value']);
        $this->assertSame('15:00', $data['seen_from']['value']);
        $this->assertNull($data['seen_to']);
    }

    public function test_a_finished_visit_reports_both_without_moving_the_promise(): void
    {
        foreach (['arrived', 'with_doctor'] as $to) {
            $this->postJson(route('api.v1.bookings.status', $this->booking), ['to' => $to])->assertOk();
        }

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:18:00', 'Africa/Cairo'));

        $this->postJson(route('api.v1.bookings.status', $this->booking), ['to' => 'done'])->assertOk();

        $data = $this->card();

        // The promise is untouched — two hours out from when it happened.
        $this->assertSame('17:00', $data['start_time']['value']);
        $this->assertSame('17:20', $data['end_time']['value']);

        $this->assertSame('15:00', $data['seen_from']['value']);
        $this->assertSame('15:18', $data['seen_to']['value']);
    }

    /**
     * A visit that never happened has nothing to report, and must not borrow
     * the scheduled times to pretend otherwise.
     */
    public function test_a_cancelled_booking_reports_no_actual_time(): void
    {
        $this->postJson(route('api.v1.bookings.cancel', $this->booking), ['reason' => 'patient_cancelled'])
            ->assertOk();

        $data = $this->card();

        $this->assertSame('17:00', $data['start_time']['value']);
        $this->assertNull($data['seen_from']);
        $this->assertNull($data['seen_to']);
    }
}
