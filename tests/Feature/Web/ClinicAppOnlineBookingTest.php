<?php

namespace Tests\Feature\Web;

use App\Enums\OnlineBookingBlockReason;
use App\Livewire\App\PatientProfile;
use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The web app's side of online-booking blocks: the patient's page shows the
 * standing and the switch, the queue shows a badge — through the same service
 * the API uses.
 */
class ClinicAppOnlineBookingTest extends TestCase
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
            'name' => 'منى سعيد',
            'phone' => '+201012225521',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function block(): void
    {
        $this->patient->update([
            'online_booking_blocked_at' => Carbon::parse('2026-11-09 06:00'),
            'online_booking_block_reason' => OnlineBookingBlockReason::NO_SHOWS,
        ]);
    }

    public function test_the_page_shows_a_blocked_patient_and_why(): void
    {
        $this->block();

        $this->actingAs($this->secretary)
            ->get(route('app.patients.show', $this->patient))
            ->assertOk()
            ->assertSee(__('patient.online_booking.blocked'))
            ->assertSee(__('patient.online_booking.reason.no_shows'))
            ->assertSee(__('patient.online_booking.allow'));
    }

    public function test_the_page_shows_an_allowed_patient_with_the_block_button(): void
    {
        $this->actingAs($this->secretary)
            ->get(route('app.patients.show', $this->patient))
            ->assertOk()
            ->assertSee(__('patient.online_booking.allowed'))
            ->assertSee(__('patient.online_booking.block'));
    }

    public function test_the_page_shows_where_bookings_came_from(): void
    {
        Booking::factory()->forClinic($this->clinic)->noShow()->selfBooked()
            ->at(Carbon::parse('2026-11-08 09:00', 'Africa/Cairo'))
            ->create(['patient_id' => $this->patient->id]);

        $this->actingAs($this->secretary)
            ->get(route('app.patients.show', $this->patient))
            ->assertOk()
            ->assertSee(__('patient.online_booking.online_bookings'))
            ->assertSee(__('patient.online_booking.clinic_bookings'))
            ->assertSee(__('patient.online_booking.last_no_show'));
    }

    public function test_the_secretary_lifts_a_block(): void
    {
        $this->block();
        $this->actingAs($this->secretary);

        Livewire::test(PatientProfile::class, ['patient' => $this->patient->id])
            ->call('allowOnlineBooking')
            ->assertSet('failed', false)
            ->assertSee(__('patient.online_booking.allowed_ok'));

        $this->assertNull($this->patient->fresh()->online_booking_blocked_at);
        $this->assertNotNull($this->patient->fresh()->online_booking_allowed_at);
    }

    public function test_the_secretary_blocks_by_hand(): void
    {
        $this->actingAs($this->secretary);

        Livewire::test(PatientProfile::class, ['patient' => $this->patient->id])
            ->call('blockOnlineBooking')
            ->assertSee(__('patient.online_booking.blocked_ok'));

        $this->assertSame(OnlineBookingBlockReason::MANUAL, $this->patient->fresh()->online_booking_block_reason);
    }

    public function test_the_queue_card_shows_the_badge(): void
    {
        $this->block();

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-11-10 11:00', 'Africa/Cairo'))
            ->create(['patient_id' => $this->patient->id]);

        $this->actingAs($this->secretary)
            ->get(route('app.queue'))
            ->assertOk()
            ->assertSee(__('patient.online_booking.blocked'));
    }

    public function test_the_queue_card_has_no_badge_for_an_allowed_patient(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-11-10 11:00', 'Africa/Cairo'))
            ->create(['patient_id' => $this->patient->id]);

        $this->actingAs($this->secretary)
            ->get(route('app.queue'))
            ->assertOk()
            ->assertDontSee(__('patient.online_booking.blocked'));
    }

    public function test_the_patient_list_shows_the_badge(): void
    {
        $this->block();

        $this->actingAs($this->secretary)
            ->get(route('app.patients'))
            ->assertOk()
            ->assertSee(__('patient.online_booking.blocked'));
    }
}
