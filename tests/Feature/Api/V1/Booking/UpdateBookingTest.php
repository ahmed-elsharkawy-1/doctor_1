<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Patient;
use App\Models\VisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class UpdateBookingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Carbon $saturday;

    private VisitType $checkup;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->saturday = Carbon::parse('2026-08-08');

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '12:00']);

        $this->checkup = $this->clinic->visitTypes()->first();
        $this->checkup->update(['price' => 300, 'duration_minutes' => 20]);

        Sanctum::actingAs($this->owner);

        $this->booking = Booking::find(
            $this->postJson(route('api.v1.bookings.store'), $this->payload())->json('data.id'),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'patient_name' => 'سارة أحمد',
            'phone' => '01012225521',
            'visit_type_id' => $this->checkup->id,
            'date' => $this->saturday->toDateString(),
            'start_time' => '09:00',
        ], $overrides);
    }

    public function test_it_moves_a_booking_to_another_time(): void
    {
        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'start_time' => '10:00',
        ]))
            ->assertOk()
            ->assertJsonPath('data.start_time.value', '10:00')
            ->assertJsonPath('data.end_time.value', '10:20');
    }

    /**
     * Its own slot must not count as a conflict with itself.
     */
    public function test_saving_a_booking_at_its_current_time_is_allowed(): void
    {
        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'notes' => 'ملاحظة',
        ]))
            ->assertOk()
            ->assertJsonPath('data.notes', 'ملاحظة');
    }

    public function test_it_cannot_be_moved_onto_another_patients_slot(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'phone' => '01098887791',
            'patient_name' => 'ريم خالد',
            'start_time' => '10:00',
        ]))->assertCreated();

        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'start_time' => '10:00',
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    public function test_changing_the_visit_type_resnapshots_price_and_duration(): void
    {
        $procedure = VisitType::factory()->procedure()->create([
            'clinic_id' => $this->clinic->id,
            'duration_minutes' => 30,
            'price' => 800,
        ]);

        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'visit_type_id' => $procedure->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.end_time.value', '09:30');

        $this->booking->refresh();

        $this->assertSame(30, $this->booking->duration_minutes);
        $this->assertEquals(800, (float) $this->booking->price);
    }

    public function test_a_booking_with_the_doctor_cannot_be_edited(): void
    {
        $this->booking->update(['status' => BookingStatus::WITH_DOCTOR]);

        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'start_time' => '10:00',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'BOOKING_NOT_EDITABLE');
    }

    public function test_an_arrived_booking_can_still_be_edited(): void
    {
        $this->booking->update(['status' => BookingStatus::ARRIVED]);

        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'start_time' => '10:00',
        ]))->assertOk();
    }

    public function test_correcting_the_name_requires_explicit_confirmation(): void
    {
        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'patient_name' => 'سارة أحمد محمد',
        ]))->assertOk();

        $this->assertSame('سارة أحمد', $this->booking->patient->refresh()->name);

        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'patient_name' => 'سارة أحمد محمد',
            'update_patient_name' => true,
        ]))->assertOk();

        $this->assertSame('سارة أحمد محمد', $this->booking->patient->refresh()->name);
    }

    public function test_the_patient_code_never_changes_when_the_name_is_corrected(): void
    {
        $codeBefore = $this->booking->patient->code;

        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'patient_name' => 'اسم مختلف تماما',
            'update_patient_name' => true,
        ]))->assertOk();

        $this->assertSame($codeBefore, $this->booking->patient->refresh()->code);
    }

    public function test_changing_the_phone_moves_the_booking_to_another_patient(): void
    {
        $this->putJson(route('api.v1.bookings.update', $this->booking), $this->payload([
            'patient_name' => 'ريم خالد',
            'phone' => '01098887791',
        ]))->assertOk();

        $this->assertSame(2, Patient::count());
        $this->assertSame('ريم خالد', $this->booking->refresh()->patient->name);
    }

    public function test_another_clinics_booking_is_not_reachable(): void
    {
        $foreign = Booking::factory()->forClinic($this->otherClinic())->create();

        $this->putJson(route('api.v1.bookings.update', $foreign), $this->payload())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BOOKING_NOT_FOUND');
    }

    public function test_it_shows_a_single_booking(): void
    {
        $this->getJson(route('api.v1.bookings.show', $this->booking))
            ->assertOk()
            ->assertJsonPath('data.id', $this->booking->id)
            ->assertJsonPath('data.patient.code', $this->booking->patient->code)
            ->assertJsonPath('data.status.value', 'booked');
    }

    public function test_showing_another_clinics_booking_is_refused(): void
    {
        $foreign = Booking::factory()->forClinic($this->otherClinic())->create();

        $this->getJson(route('api.v1.bookings.show', $foreign))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BOOKING_NOT_FOUND');
    }
}
