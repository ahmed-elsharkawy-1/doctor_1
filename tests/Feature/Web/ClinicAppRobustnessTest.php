<?php

namespace Tests\Feature\Web;

use App\Enums\UserRole;
use App\Livewire\App\NewBooking;
use App\Livewire\App\Queue;
use App\Models\Booking;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Things that reached the screens from outside and broke them. Each of these
 * was a live defect found in review.
 */
class ClinicAppRobustnessTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_platform_operator_is_sent_to_the_panel_not_signed_out(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);

        $this->actingAs($admin)
            ->get(route('app.queue'))
            ->assertRedirect(config('clinic.panel.path'));

        // Mistyping an address must not drop them out of Filament.
        $this->assertAuthenticatedAs($admin);
    }

    public function test_livewire_actions_refuse_a_disabled_session_user(): void
    {
        $booking = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))
            ->create();

        $page = Livewire::actingAs($this->owner)
            ->test(Queue::class);

        $this->owner->update(['is_active' => false]);

        $page
            ->call('arrive', $booking->id)
            ->assertForbidden();

        $this->assertSame('booked', $booking->fresh()->status->value);
    }

    public function test_livewire_actions_refuse_a_panel_only_user(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);

        Livewire::actingAs($admin)
            ->test(Queue::class)
            ->assertForbidden();
    }

    public function test_a_junk_date_in_the_query_string_falls_back_to_today(): void
    {
        // `date` is bound to the query string, so this is one URL edit away.
        Livewire::actingAs($this->owner)
            ->withQueryParams(['date' => 'not-a-date'])
            ->test(Queue::class)
            ->assertOk()
            ->assertSet('date', '2026-09-03');
    }

    public function test_stepping_the_day_from_a_junk_date_still_works(): void
    {
        Livewire::actingAs($this->owner)
            ->withQueryParams(['date' => '../../etc/passwd'])
            ->test(Queue::class)
            ->call('goToDay', 1)
            ->assertOk()
            ->assertSet('date', '2026-09-04');
    }

    public function test_another_clinics_patient_cannot_be_selected(): void
    {
        $other = $this->otherClinic();
        $theirs = Patient::factory()->create(['clinic_id' => $other->id]);

        // ApiException renders itself as JSON, which would break the response.
        Livewire::actingAs($this->owner)
            ->test(NewBooking::class)
            ->call('selectPatient', $theirs->id)
            ->assertOk()
            ->assertSet('patientId', null)
            ->assertSet('failed', true);
    }

    public function test_a_junk_date_does_not_break_the_booking_screen(): void
    {
        Livewire::actingAs($this->owner)
            ->test(NewBooking::class)
            ->set('date', 'not-a-date')
            ->call('save')
            ->assertOk()
            ->assertHasErrors('date');

        $this->assertSame(0, Booking::count());
    }
}
