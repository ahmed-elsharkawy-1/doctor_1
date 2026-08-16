<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class PostponeTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->today = Carbon::parse('2026-08-08');

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '14:00']);

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $time, string $name = 'مريضة'): Booking
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => $name,
        ]);

        return Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->today->copy()->setTime(...array_map('intval', explode(':', $time))))
            ->create(['patient_id' => $patient->id]);
    }

    public function test_candidates_are_the_patients_still_in_play_today(): void
    {
        $this->booking('09:00', 'محجوزة');
        $arrived = $this->booking('10:00', 'وصلت');
        $done = $this->booking('11:00', 'خلصت');

        $this->postJson(route('api.v1.bookings.status', $arrived), ['to' => 'arrived'])->assertOk();
        $done->update(['status' => BookingStatus::DONE]);

        $items = $this->getJson(route('api.v1.postpone.candidates'))->assertOk()->json('data.items');

        $this->assertSame(['محجوزة', 'وصلت'], array_column(array_column($items, 'patient'), 'name'));
    }

    public function test_postponing_everyone_cancels_them_with_the_emergency_reason(): void
    {
        $first = $this->booking('09:00');
        $second = $this->booking('10:00');

        $this->postJson(route('api.v1.postpone'), [])
            ->assertOk()
            ->assertJsonPath('data.postponed_count', 2)
            ->assertJsonCount(2, 'data.items');

        foreach ([$first, $second] as $booking) {
            $booking->refresh();
            $this->assertSame(BookingStatus::CANCELLED, $booking->status);
            $this->assertSame(CancelReason::EMERGENCY, $booking->cancel_reason);
        }
    }

    public function test_it_can_postpone_only_the_selected_patients(): void
    {
        $affected = $this->booking('09:00', 'متأثرة');
        $untouched = $this->booking('10:00', 'مكملة');

        $this->postJson(route('api.v1.postpone'), ['booking_ids' => [$affected->id]])
            ->assertOk()
            ->assertJsonPath('data.postponed_count', 1);

        $this->assertSame(BookingStatus::CANCELLED, $affected->refresh()->status);
        $this->assertSame(BookingStatus::BOOKED, $untouched->refresh()->status);
    }

    /**
     * Freeing the slots is the entire point — otherwise the secretary cannot
     * rebook the same patients into the times she just cleared.
     */
    public function test_postponing_frees_the_slots(): void
    {
        $booking = $this->booking('09:00');

        $this->postJson(route('api.v1.postpone'), [])->assertOk();

        $slots = $this->getJson(route('api.v1.slots', [
            'date' => '2026-08-08',
            'visit_type_id' => $booking->visit_type_id,
        ]))->json('data.slots');

        $this->assertTrue(collect($slots)->firstWhere('start_time.value', '09:00')['is_available']);
    }

    public function test_a_patient_with_the_doctor_is_not_postponed(): void
    {
        $inRoom = $this->booking('09:00', 'جوه');

        $this->postJson(route('api.v1.bookings.status', $inRoom), ['to' => 'arrived'])->assertOk();
        $this->postJson(route('api.v1.bookings.status', $inRoom), ['to' => 'with_doctor'])->assertOk();

        $this->postJson(route('api.v1.postpone'), [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'NOTHING_TO_POSTPONE');

        $this->assertSame(BookingStatus::WITH_DOCTOR, $inRoom->refresh()->status);
    }

    public function test_postponing_an_empty_day_is_refused(): void
    {
        $this->postJson(route('api.v1.postpone'), [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'NOTHING_TO_POSTPONE');
    }

    public function test_the_postponed_patients_become_the_call_list(): void
    {
        $booking = $this->booking('09:00', 'سارة');

        $this->postJson(route('api.v1.postpone'), [])->assertOk();

        $row = $this->getJson(route('api.v1.rebooking-list'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.booking_id', $booking->id)
            ->assertJsonPath('data.items.0.patient.name', 'سارة')
            ->assertJsonPath('data.items.0.contacted', false)
            ->assertJsonPath('data.items.0.original_start_time.value', '09:00')
            ->json('data.items.0');

        // Unmasked: every row on the call list has a call action.
        $this->assertSame($booking->patient->phone, $row['patient']['phone']['value']);
        $this->assertStringStartsWith('01', $row['patient']['phone']['display']);
    }

    public function test_marking_a_row_as_contacted_sticks(): void
    {
        $booking = $this->booking('09:00');

        $this->postJson(route('api.v1.postpone'), [])->assertOk();

        $this->postJson(route('api.v1.bookings.contacted', $booking))
            ->assertOk()
            ->assertJsonPath('data.booking_id', $booking->id);

        $this->getJson(route('api.v1.rebooking-list'))
            ->assertJsonPath('data.items.0.contacted', true);
    }

    /**
     * A cancelled patient stays on the worklist until she actually has a new
     * appointment.
     */
    public function test_rebooking_takes_the_patient_off_the_list(): void
    {
        $booking = $this->booking('09:00', 'سارة');
        $patient = $booking->patient;

        $this->postJson(route('api.v1.postpone'), [])->assertOk();

        $this->assertCount(1, $this->getJson(route('api.v1.rebooking-list'))->json('data.items'));

        $this->postJson(route('api.v1.bookings.store'), [
            'patient_name' => $patient->name,
            'phone' => $patient->phone,
            'visit_type_id' => $booking->visit_type_id,
            'date' => '2026-08-08',
            'start_time' => '12:00',
            'rebooking_for_booking_id' => $booking->id,
        ])->assertCreated();

        $this->assertCount(0, $this->getJson(route('api.v1.rebooking-list'))->json('data.items'));
        $this->assertNotNull($booking->refresh()->rebooked_booking_id);
    }

    public function test_the_rebooking_list_reports_who_still_needs_rebooking(): void
    {
        $this->booking('09:00');
        $this->booking('10:00');

        $this->postJson(route('api.v1.postpone'), [])->assertOk();

        $this->getJson(route('api.v1.rebooking-list'))
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    public function test_a_booking_that_is_not_awaiting_rebooking_cannot_be_linked(): void
    {
        $booking = $this->booking('09:00');

        $this->postJson(route('api.v1.bookings.store'), [
            'patient_name' => 'ريم خالد',
            'phone' => '01098887791',
            'visit_type_id' => $booking->visit_type_id,
            'date' => '2026-08-08',
            'start_time' => '12:00',
            'rebooking_for_booking_id' => $booking->id,
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BOOKING_NOT_FOUND');
    }

    public function test_another_clinics_patients_are_never_postponed(): void
    {
        $other = $this->otherClinic();

        $foreign = Booking::factory()->forClinic($other)
            ->at($this->today->copy()->setTime(9, 0))->create();

        $this->booking('10:00');

        $this->postJson(route('api.v1.postpone'), [])
            ->assertOk()
            ->assertJsonPath('data.postponed_count', 1);

        $this->assertSame(BookingStatus::BOOKED, $foreign->refresh()->status);
    }
}
