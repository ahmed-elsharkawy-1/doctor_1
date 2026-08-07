<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class StatusTransitionTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '14:00']);

        $this->booking = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-08 09:00', 'Africa/Cairo'))
            ->create();

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_happy_path_walks_the_whole_chain(): void
    {
        $this->postJson(route('api.v1.bookings.arrive', $this->booking))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'arrived')
            ->assertJsonPath('data.status.display', 'في الانتظار');

        $this->postJson(route('api.v1.bookings.call-in', $this->booking))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'with_doctor')
            ->assertJsonPath('data.status.display', 'مع الدكتورة');

        $this->postJson(route('api.v1.bookings.complete', $this->booking))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'done');

        $this->assertSame(BookingStatus::DONE, $this->booking->refresh()->status);
    }

    public function test_each_step_stamps_its_own_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 09:05:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.arrive', $this->booking))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:20:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.call-in', $this->booking))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:35:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.complete', $this->booking))->assertOk();

        $this->booking->refresh();

        $this->assertSame('09:05', $this->booking->arrived_at->format('H:i'));
        $this->assertSame('09:20', $this->booking->called_in_at->format('H:i'));
        $this->assertSame('09:35', $this->booking->completed_at->format('H:i'));
    }

    /**
     * The arrival stamp is what the PRD's wait-time metric will be measured
     * from, so it has to be real rather than the appointment time.
     */
    public function test_the_arrival_stamp_is_when_she_arrived_not_when_she_was_booked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:42:00', 'Africa/Cairo'));

        $this->postJson(route('api.v1.bookings.arrive', $this->booking))->assertOk();

        $this->assertSame('10:42', $this->booking->refresh()->arrived_at->format('H:i'));
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $this->postJson(route('api.v1.bookings.call-in', $this->booking))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION')
            ->assertJsonPath('error.details.from', 'booked')
            ->assertJsonPath('error.details.to', 'with_doctor')
            ->assertJsonPath('error.details.expected', 'arrived');

        $this->postJson(route('api.v1.bookings.complete', $this->booking))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_a_finished_booking_cannot_be_advanced_again(): void
    {
        $this->postJson(route('api.v1.bookings.arrive', $this->booking))->assertOk();
        $this->postJson(route('api.v1.bookings.call-in', $this->booking))->assertOk();
        $this->postJson(route('api.v1.bookings.complete', $this->booking))->assertOk();

        $this->postJson(route('api.v1.bookings.arrive', $this->booking))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_it_records_a_no_show(): void
    {
        $this->postJson(route('api.v1.bookings.cancel', $this->booking), ['reason' => 'no_show'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled')
            ->assertJsonPath('data.cancel_reason.value', 'no_show')
            ->assertJsonPath('data.cancel_reason.display', 'لم تحضر');

        $this->assertSame(CancelReason::NO_SHOW, $this->booking->refresh()->cancel_reason);
    }

    public function test_it_records_a_patient_cancellation(): void
    {
        $this->postJson(route('api.v1.bookings.cancel', $this->booking), ['reason' => 'patient_cancelled'])
            ->assertOk()
            ->assertJsonPath('data.cancel_reason.value', 'patient_cancelled');
    }

    /**
     * `emergency` belongs to the postpone flow and `incomplete` to the nightly
     * job — neither is something the secretary picks.
     */
    public function test_system_only_reasons_cannot_be_chosen(): void
    {
        foreach (['emergency', 'incomplete'] as $reason) {
            $this->postJson(route('api.v1.bookings.cancel', $this->booking), ['reason' => $reason])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    public function test_a_patient_with_the_doctor_cannot_be_cancelled(): void
    {
        $this->postJson(route('api.v1.bookings.arrive', $this->booking))->assertOk();
        $this->postJson(route('api.v1.bookings.call-in', $this->booking))->assertOk();

        $this->postJson(route('api.v1.bookings.cancel', $this->booking), ['reason' => 'no_show'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'BOOKING_NOT_CANCELLABLE');
    }

    public function test_an_arrived_patient_can_still_be_cancelled(): void
    {
        $this->postJson(route('api.v1.bookings.arrive', $this->booking))->assertOk();

        $this->postJson(route('api.v1.bookings.cancel', $this->booking), ['reason' => 'patient_cancelled'])
            ->assertOk();
    }

    /**
     * Freeing the slot is what makes rebooking possible.
     */
    public function test_cancelling_frees_the_slot_immediately(): void
    {
        $visitTypeId = $this->booking->visit_type_id;

        $taken = $this->getJson(route('api.v1.slots', [
            'date' => '2026-08-08',
            'visit_type_id' => $visitTypeId,
        ]))->json('data.slots');

        $this->assertFalse(collect($taken)->firstWhere('start_time.value', '09:00')['is_available']);

        $this->postJson(route('api.v1.bookings.cancel', $this->booking), ['reason' => 'no_show'])->assertOk();

        $freed = $this->getJson(route('api.v1.slots', [
            'date' => '2026-08-08',
            'visit_type_id' => $visitTypeId,
        ]))->json('data.slots');

        $this->assertTrue(collect($freed)->firstWhere('start_time.value', '09:00')['is_available']);
    }

    public function test_another_clinics_booking_cannot_be_advanced(): void
    {
        $foreign = Booking::factory()->forClinic($this->otherClinic())->create();

        $this->postJson(route('api.v1.bookings.arrive', $foreign))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BOOKING_NOT_FOUND');
    }

    public function test_a_reason_is_required_to_cancel(): void
    {
        $this->postJson(route('api.v1.bookings.cancel', $this->booking), [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['reason']]]);
    }
}
