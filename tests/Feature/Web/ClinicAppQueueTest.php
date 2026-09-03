<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\UserRole;
use App\Livewire\App\Queue;
use App\Models\Booking;
use App\Models\User;
use App\Services\V1\Queue\QueuePositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ClinicAppQueueTest extends TestCase
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

    private function booking(string $time = '09:40'): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 '.$time, $this->clinic->timezone))
            ->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Getting in
    |--------------------------------------------------------------------------
    */

    public function test_the_queue_needs_a_signed_in_account(): void
    {
        $this->get(route('app.queue'))->assertRedirect(route('app.login'));
    }

    public function test_a_clinic_account_can_sign_in(): void
    {
        $this->post(route('app.login.store'), [
            'email' => $this->owner->email,
            'password' => 'password',
        ])->assertRedirect(route('app.queue'));

        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_a_wrong_password_is_rejected_with_the_api_message(): void
    {
        $this->from(route('app.login'))
            ->post(route('app.login.store'), [
                'email' => $this->owner->email,
                'password' => 'not-the-password',
            ])
            ->assertRedirect(route('app.login'))
            ->assertSessionHasErrors(['email' => __('auth.invalid_credentials')]);

        $this->assertGuest();
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $this->owner->update(['is_active' => false]);

        $this->from(route('app.login'))
            ->post(route('app.login.store'), [
                'email' => $this->owner->email,
                'password' => 'password',
            ])
            ->assertSessionHasErrors(['email' => __('auth.account_inactive')]);

        $this->assertGuest();
    }

    public function test_the_platform_operator_cannot_sign_in_to_the_clinic_app(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::SUPER_ADMIN,
            'password' => 'password',
        ]);

        $this->from(route('app.login'))
            ->post(route('app.login.store'), [
                'email' => $admin->email,
                'password' => 'password',
            ])
            ->assertSessionHasErrors(['email' => __('auth.role_not_allowed')]);

        $this->assertGuest();
    }

    public function test_an_account_can_sign_out(): void
    {
        $this->actingAs($this->owner)
            ->post(route('app.logout'))
            ->assertRedirect(route('app.login'));

        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function test_the_queue_shows_todays_patients(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->owner)
            ->get(route('app.queue'))
            ->assertOk()
            ->assertSee($booking->patient->name)
            ->assertSee($this->clinic->name);
    }

    public function test_another_clinics_bookings_never_appear(): void
    {
        $other = $this->otherClinic();
        $theirs = Booking::factory()->forClinic($other)
            ->at(Carbon::parse('2026-09-03 09:00', $other->timezone))->create();

        $this->actingAs($this->owner)
            ->get(route('app.queue'))
            ->assertOk()
            ->assertDontSee($theirs->patient->name);
    }

    public function test_the_list_is_in_queue_order(): void
    {
        $late = $this->booking('11:00');
        $early = $this->booking('09:00');

        Livewire::actingAs($this->owner)
            ->test(Queue::class)
            ->assertSeeInOrder([$early->patient->name, $late->patient->name]);
    }

    public function test_the_day_can_be_stepped_and_reset(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Queue::class)
            ->assertSet('date', '2026-09-03')
            ->call('goToDay', 1)
            ->assertSet('date', '2026-09-04')
            ->call('today')
            ->assertSet('date', '2026-09-03');
    }

    /*
    |--------------------------------------------------------------------------
    | Status taps — the thing the patient's counter depends on
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_is_walked_through_the_whole_visit(): void
    {
        $booking = $this->booking();

        $page = Livewire::actingAs($this->owner)->test(Queue::class);

        $page->call('arrive', $booking->id);
        $this->assertSame(BookingStatus::ARRIVED, $booking->fresh()->status);

        $page->call('callIn', $booking->id);
        $this->assertSame(BookingStatus::WITH_DOCTOR, $booking->fresh()->status);

        $page->call('complete', $booking->id);
        $this->assertSame(BookingStatus::DONE, $booking->fresh()->status);
    }

    public function test_arriving_stamps_the_queue_entry_time(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->owner)
            ->test(Queue::class)
            ->call('arrive', $booking->id);

        $this->assertNotNull($booking->fresh()->queue_entered_at);
    }

    public function test_a_patient_can_be_marked_no_show(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->owner)
            ->test(Queue::class)
            ->call('noShow', $booking->id);

        $this->assertSame(BookingStatus::NO_SHOW, $booking->fresh()->status);
    }

    public function test_cancelling_asks_for_a_reason_then_records_it(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->owner)
            ->test(Queue::class)
            ->call('confirmCancel', $booking->id)
            ->assertSet('cancelling', $booking->id)
            ->call('cancel', $booking->id, CancelReason::PATIENT_CANCELLED->value)
            ->assertSet('cancelling', null);

        $booking->refresh();

        $this->assertSame(BookingStatus::CANCELLED, $booking->status);
        $this->assertSame(CancelReason::PATIENT_CANCELLED, $booking->cancel_reason);
    }

    public function test_an_impossible_transition_is_reported_not_thrown(): void
    {
        $booking = $this->booking();

        // booked cannot jump straight to done.
        Livewire::actingAs($this->owner)
            ->test(Queue::class)
            ->call('complete', $booking->id)
            ->assertOk()
            ->assertSet('failed', true)
            ->assertSetStrict('message', __('booking.invalid_transition', [
                'from' => BookingStatus::BOOKED->label(),
                'to' => BookingStatus::DONE->label(),
            ]));

        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }

    public function test_another_clinics_booking_cannot_be_touched(): void
    {
        $other = $this->otherClinic();
        $theirs = Booking::factory()->forClinic($other)
            ->at(Carbon::parse('2026-09-03 09:00', $other->timezone))->create();

        Livewire::actingAs($this->owner)
            ->test(Queue::class)
            ->call('arrive', $theirs->id);

        $this->assertSame(BookingStatus::BOOKED, $theirs->fresh()->status);
    }

    public function test_the_status_taps_drive_the_patients_counter(): void
    {
        $first = $this->booking('09:00');
        $second = $this->booking('09:40');

        $positions = app(QueuePositionService::class);

        $this->assertSame(1, $positions->for($second)->ahead);

        // The secretary finishes the first patient; the waiting count drops.
        $page = Livewire::actingAs($this->owner)->test(Queue::class);
        $page->call('arrive', $first->id);
        $page->call('callIn', $first->id);
        $page->call('complete', $first->id);

        $this->assertSame(0, $positions->for($second->fresh())->ahead);
    }
}
