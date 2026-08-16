<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\ClinicHoliday;
use App\Models\Patient;
use App\Models\VisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class CreateBookingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Carbon $saturday;

    private VisitType $checkup;

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

    public function test_it_books_a_new_patient_and_generates_her_code(): void
    {
        $response = $this->postJson(route('api.v1.bookings.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'booked')
            ->assertJsonPath('data.start_time.value', '09:00')
            ->assertJsonPath('data.end_time.value', '09:20')
            ->assertJsonPath('data.patient.name', 'سارة أحمد');

        $this->assertSame(
            Patient::codeForId($response->json('data.patient.id')),
            $response->json('data.patient.code'),
        );

        $this->assertDatabaseHas('patients', [
            'clinic_id' => $this->clinic->id,
            'phone' => '+201012225521',
            'age' => null,
        ]);
    }

    public function test_it_records_age_and_whatsapp_opt_in_for_a_new_patient(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'age' => 37,
            'whatsapp_opt_in' => true,
        ]))->assertCreated();

        $patient = Patient::first();

        $this->assertSame(37, $patient->age);
        $this->assertNotNull($patient->whatsapp_opt_in_at);
    }

    public function test_it_can_book_an_existing_patient_by_id(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);

        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'patient_id' => $patient->id,
            'patient_name' => 'اسم يتجاهله السيرفر',
            'phone' => '01099999999',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.patient.id', $patient->id);

        $this->assertSame(1, Patient::count());
    }

    public function test_a_patient_id_from_another_clinic_is_not_found(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->otherClinic()->id]);

        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'patient_id' => $patient->id,
        ]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PATIENT_NOT_FOUND');
    }

    public function test_the_price_and_duration_are_snapshotted_onto_the_booking(): void
    {
        $id = $this->postJson(route('api.v1.bookings.store'), $this->payload())->json('data.id');

        $this->checkup->update(['price' => 500, 'duration_minutes' => 45]);

        $booking = Booking::find($id);

        $this->assertEquals(300, (float) $booking->price);
        $this->assertSame(20, $booking->duration_minutes);
    }

    public function test_booking_the_same_patient_again_reuses_her_record(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload())->assertCreated();

        $second = $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'start_time' => '10:00',
            // Same number typed differently.
            'phone' => '+20 101 222 5521',
        ]))->assertCreated();

        $this->assertSame(1, Patient::count());
        $this->assertSame(
            Patient::firstOrFail()->code,
            $second->json('data.patient.code'),
        );
    }

    public function test_a_taken_slot_is_refused(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload())->assertCreated();

        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'phone' => '01098887791',
            'patient_name' => 'ريم خالد',
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');

        $this->assertSame(1, Booking::count());
    }

    public function test_an_overlapping_longer_visit_is_refused(): void
    {
        $procedure = VisitType::factory()->procedure()->create([
            'clinic_id' => $this->clinic->id,
            'duration_minutes' => 30,
        ]);

        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'visit_type_id' => $procedure->id,
        ]))->assertCreated();

        // 09:20 falls inside the 09:00-09:30 procedure.
        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'phone' => '01098887791',
            'start_time' => '09:20',
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    public function test_a_time_outside_working_hours_is_refused(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload(['start_time' => '15:00']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_OUTSIDE_WORKING_HOURS');
    }

    public function test_a_visit_that_would_not_finish_before_closing_is_refused(): void
    {
        // 11:50 + 20 minutes runs past the 12:00 close.
        $this->postJson(route('api.v1.bookings.store'), $this->payload(['start_time' => '11:50']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_OUTSIDE_WORKING_HOURS');
    }

    public function test_a_closed_day_is_refused(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'date' => $this->saturday->copy()->addDay()->toDateString(),
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CLINIC_CLOSED_THAT_DAY');
    }

    public function test_a_holiday_is_refused(): void
    {
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => $this->saturday->toDateString(),
        ]);

        $this->postJson(route('api.v1.bookings.store'), $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CLINIC_CLOSED_THAT_DAY');
    }

    public function test_a_date_beyond_the_booking_window_is_refused(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'date' => $this->saturday->copy()->addDays(30)->toDateString(),
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_OUTSIDE_WINDOW');
    }

    /**
     * The secretary can still squeeze in an urgent case (SPEC decision #16).
     */
    public function test_force_books_past_a_taken_slot_and_flags_the_booking(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload())->assertCreated();

        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'phone' => '01098887791',
            'patient_name' => 'ريم خالد',
            'force' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.is_overbooked', true);

        $this->assertSame(2, Booking::count());
    }

    public function test_force_also_books_a_closed_day(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload([
            'date' => $this->saturday->copy()->addDay()->toDateString(),
            'force' => true,
        ]))->assertCreated();
    }

    public function test_a_hidden_visit_type_cannot_be_booked(): void
    {
        $hidden = VisitType::factory()->hidden()->create(['clinic_id' => $this->clinic->id]);

        $this->postJson(route('api.v1.bookings.store'), $this->payload(['visit_type_id' => $hidden->id]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VISIT_TYPE_INACTIVE');
    }

    public function test_another_clinics_visit_type_cannot_be_booked(): void
    {
        $foreign = $this->otherClinic()->visitTypes()->first();

        $this->postJson(route('api.v1.bookings.store'), $this->payload(['visit_type_id' => $foreign->id]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VISIT_TYPE_NOT_FOUND');
    }

    public function test_an_unparseable_phone_is_rejected(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload(['phone' => '12345']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PHONE_NUMBER');
    }

    public function test_a_new_booking_starts_in_the_booked_status(): void
    {
        $id = $this->postJson(route('api.v1.bookings.store'), $this->payload())->json('data.id');

        $this->assertSame(BookingStatus::BOOKED, Booking::find($id)->status);
    }

    public function test_it_records_who_created_the_booking(): void
    {
        $id = $this->postJson(route('api.v1.bookings.store'), $this->payload())->json('data.id');

        $this->assertSame($this->owner->id, Booking::find($id)->created_by);
    }

    public function test_the_clinic_account_sees_the_price(): void
    {
        Sanctum::actingAs($this->secretary);

        $data = $this->postJson(route('api.v1.bookings.store'), $this->payload())->json('data');

        $this->assertSame('300.00', $data['price']['value']);
    }

    public function test_the_owner_sees_the_price(): void
    {
        $this->postJson(route('api.v1.bookings.store'), $this->payload())
            ->assertJsonPath('data.price.value', '300.00');
    }
}
