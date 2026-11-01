<?php

namespace Tests\Feature\Api\V1\Patients;

use App\Enums\OnlineBookingBlockReason;
use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * What the clinic sees of a patient's online-booking standing, and the one
 * switch it has over it.
 */
class PatientOnlineBookingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['no_show_block_enabled' => true]);

        $this->patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => '+201012225521',
        ]);

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function noShow(string $date, bool $online = false): Booking
    {
        $factory = Booking::factory()->forClinic($this->clinic)->noShow()
            ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'));

        return ($online ? $factory->selfBooked() : $factory)->create(['patient_id' => $this->patient->id]);
    }

    private function block(OnlineBookingBlockReason $reason = OnlineBookingBlockReason::NO_SHOWS): void
    {
        $this->patient->update([
            'online_booking_blocked_at' => Carbon::parse('2026-11-09 06:00'),
            'online_booking_block_reason' => $reason,
        ]);
    }

    private function file(): array
    {
        return $this->getJson(route('api.v1.patients.show', $this->patient))->assertOk()->json('data');
    }

    /*
    |--------------------------------------------------------------------------
    | The patient's page
    |--------------------------------------------------------------------------
    */

    public function test_an_allowed_patient_shows_as_allowed(): void
    {
        $online = $this->file()['online_booking'];

        $this->assertTrue($online['allowed']);
        $this->assertNull($online['blocked_at']);
        $this->assertNull($online['reason']);
        $this->assertSame(0, $online['counted_no_shows']);
        $this->assertSame(2, $online['threshold']);
        $this->assertSame(30, $online['window_days']);
        $this->assertTrue($online['rule_enabled']);
    }

    public function test_a_blocked_patient_shows_when_and_why(): void
    {
        $this->noShow('2026-11-02');
        $this->noShow('2026-11-08');
        $this->block();

        $online = $this->file()['online_booking'];

        $this->assertFalse($online['allowed']);
        $this->assertNotNull($online['blocked_at']);
        $this->assertSame('no_shows', $online['reason']['value']);
        $this->assertSame(__('patient.online_booking.reason.no_shows'), $online['reason']['display']);
        $this->assertSame(2, $online['counted_no_shows']);
    }

    public function test_the_summary_adds_the_last_no_show_and_where_bookings_came_from(): void
    {
        $this->noShow('2026-10-20', online: true);
        $this->noShow('2026-11-08');
        Booking::factory()->forClinic($this->clinic)->done()->selfBooked()
            ->at(Carbon::parse('2026-11-01 09:00', 'Africa/Cairo'))
            ->create(['patient_id' => $this->patient->id]);

        $summary = $this->file()['summary'];

        $this->assertSame('2026-11-08', $summary['last_no_show']['value']);
        $this->assertSame(2, $summary['online_bookings_count']);
        $this->assertSame(1, $summary['clinic_bookings_count']);
        $this->assertSame(2, $summary['no_show_count']);
    }

    /*
    |--------------------------------------------------------------------------
    | The switch
    |--------------------------------------------------------------------------
    */

    public function test_the_clinic_lifts_a_block(): void
    {
        $this->block();

        $this->postJson(route('api.v1.patients.online-booking', $this->patient), ['allowed' => true])
            ->assertOk()
            ->assertJsonPath('data.online_booking.allowed', true)
            ->assertJsonPath('data.online_booking.reason', null);

        $this->assertNull($this->patient->fresh()->online_booking_blocked_at);
        $this->assertNotNull($this->patient->fresh()->online_booking_allowed_at);
    }

    public function test_the_clinic_blocks_by_hand(): void
    {
        $this->postJson(route('api.v1.patients.online-booking', $this->patient), ['allowed' => false])
            ->assertOk()
            ->assertJsonPath('data.online_booking.allowed', false)
            ->assertJsonPath('data.online_booking.reason.value', 'manual');

        $this->assertSame(OnlineBookingBlockReason::MANUAL, $this->patient->fresh()->online_booking_block_reason);
    }

    public function test_the_doctor_can_use_it_too(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.patients.online-booking', $this->patient), ['allowed' => false])
            ->assertOk();
    }

    public function test_allowed_is_required_and_must_be_true_or_false(): void
    {
        $this->postJson(route('api.v1.patients.online-booking', $this->patient), [])
            ->assertStatus(422);

        $this->postJson(route('api.v1.patients.online-booking', $this->patient), ['allowed' => 'maybe'])
            ->assertStatus(422);
    }

    public function test_another_clinics_patient_cannot_be_switched(): void
    {
        $foreign = Patient::factory()->create(['clinic_id' => $this->otherClinic()->id]);

        $this->postJson(route('api.v1.patients.online-booking', $foreign), ['allowed' => false])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PATIENT_NOT_FOUND');

        $this->assertNull($foreign->fresh()->online_booking_blocked_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Where the secretary meets the patient
    |--------------------------------------------------------------------------
    */

    public function test_a_booking_carries_the_patients_flag(): void
    {
        $this->block();

        $booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-11-11 09:00', 'Africa/Cairo'))
            ->create(['patient_id' => $this->patient->id]);

        $this->getJson(route('api.v1.bookings.show', $booking))
            ->assertOk()
            ->assertJsonPath('data.patient.online_booking_blocked', true);
    }

    public function test_the_phone_lookup_carries_the_flag(): void
    {
        $this->block();

        $this->postJson(route('api.v1.patients.lookup'), ['phone' => '01012225521'])
            ->assertOk()
            ->assertJsonPath('data.patient.online_booking_blocked', true);
    }

    public function test_search_results_carry_the_flag(): void
    {
        $this->block();

        $this->getJson(route('api.v1.patients.index', ['q' => $this->patient->code]))
            ->assertOk()
            ->assertJsonPath('data.items.0.online_booking_blocked', true);
    }
}
