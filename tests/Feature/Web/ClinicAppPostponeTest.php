<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Livewire\App\NewBooking;
use App\Livewire\App\Postpone;
use App\Livewire\App\Rebooking;
use App\Models\Booking;
use App\Services\V1\Booking\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ClinicAppPostponeTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday the demo clinic is open on, mid-morning.
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $this->today = Carbon::now($this->clinic->timezone)->startOfDay();

        // A provisioned clinic starts with every day closed. The rebooking
        // test needs a real day to move a patient to.
        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bookingAt(string $time): Booking
    {
        return Booking::factory()->forClinic($this->clinic)
            ->at($this->today->copy()->setTimeFromTimeString($time))
            ->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Postponing
    |--------------------------------------------------------------------------
    */

    public function test_the_screen_lists_todays_pending_bookings(): void
    {
        $this->bookingAt('11:00');
        $this->bookingAt('12:00');

        // A finished visit is not a candidate — it already happened.
        Booking::factory()->forClinic($this->clinic)->done()
            ->at($this->today->copy()->setTimeFromTimeString('09:00'))->create();

        Livewire::actingAs($this->owner)
            ->test(Postpone::class)
            ->assertViewHas('candidates', fn ($candidates) => $candidates->count() === 2);
    }

    public function test_postponing_cancels_everyone_and_frees_the_day(): void
    {
        $first = $this->bookingAt('11:00');
        $second = $this->bookingAt('12:00');

        Livewire::actingAs($this->owner)
            ->test(Postpone::class)
            ->call('confirm')
            ->call('postpone')
            ->assertSet('failed', false)
            ->assertSet('done', true);

        foreach ([$first, $second] as $booking) {
            $booking->refresh();

            $this->assertSame(BookingStatus::CANCELLED, $booking->status);
            $this->assertSame(CancelReason::EMERGENCY, $booking->cancel_reason);
        }
    }

    public function test_only_the_picked_patients_are_postponed(): void
    {
        $picked = $this->bookingAt('11:00');
        $spared = $this->bookingAt('12:00');

        Livewire::actingAs($this->owner)
            ->test(Postpone::class)
            ->call('toggle', $picked->id)
            ->call('confirm')
            ->assertViewHas('affected', fn ($affected) => $affected->count() === 1)
            ->call('postpone')
            ->assertSet('failed', false);

        $this->assertSame(BookingStatus::CANCELLED, $picked->fresh()->status);
        $this->assertSame(BookingStatus::BOOKED, $spared->fresh()->status);
    }

    public function test_an_empty_day_reports_rather_than_throwing(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Postpone::class)
            ->call('postpone')
            // ApiException would render as JSON and break the Livewire response.
            ->assertSet('failed', true)
            ->assertSet('done', false);
    }

    public function test_moving_off_the_day_clears_the_selection(): void
    {
        $booking = $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)
            ->test(Postpone::class)
            ->call('toggle', $booking->id)
            ->call('goToDay', 1)
            ->assertSet('selected', [])
            ->assertSet('confirming', false);
    }

    public function test_postponing_needs_the_ability(): void
    {
        $this->get(route('app.postpone'))->assertRedirect(route('app.login'));
    }

    /*
    |--------------------------------------------------------------------------
    | The call list
    |--------------------------------------------------------------------------
    */

    public function test_postponed_patients_land_on_the_call_list(): void
    {
        $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)->test(Postpone::class)
            ->call('confirm')->call('postpone');

        Livewire::actingAs($this->owner)
            ->test(Rebooking::class)
            ->assertViewHas('bookings', fn ($bookings) => $bookings->count() === 1);
    }

    public function test_an_ordinary_cancellation_is_not_a_rebooking(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->cancelled(CancelReason::PATIENT_CANCELLED)
            ->at($this->today->copy()->setTimeFromTimeString('11:00'))
            ->create();

        Livewire::actingAs($this->owner)
            ->test(Rebooking::class)
            ->assertViewHas('bookings', fn ($bookings) => $bookings->isEmpty());
    }

    public function test_marking_contacted_does_not_rebook_anyone(): void
    {
        $booking = $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)->test(Postpone::class)
            ->call('confirm')->call('postpone');

        Livewire::actingAs($this->owner)
            ->test(Rebooking::class)
            ->call('markContacted', $booking->id)
            ->assertSet('failed', false)
            // Still owed an appointment: ticking the row is only a bookmark.
            ->assertViewHas('bookings', fn ($bookings) => $bookings->count() === 1);

        $this->assertNotNull($booking->fresh()->contacted_at);
    }

    public function test_another_clinics_booking_cannot_be_ticked(): void
    {
        $other = $this->otherClinic();

        $stranger = Booking::factory()->forClinic($other)
            ->at($this->today->copy()->setTimeFromTimeString('11:00'))->create();

        Livewire::actingAs($this->owner)
            ->test(Rebooking::class)
            ->call('markContacted', $stranger->id)
            ->assertSet('failed', true);

        $this->assertNull($stranger->fresh()->contacted_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Rebooking from the call list
    |--------------------------------------------------------------------------
    */

    public function test_booking_a_replacement_takes_the_patient_off_the_list(): void
    {
        $original = $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)->test(Postpone::class)
            ->call('confirm')->call('postpone');

        [$date, $slot] = $this->nextFreeSlot();

        Livewire::actingAs($this->owner)
            ->test(NewBooking::class, ['rebookingFor' => $original->id])
            // The patient came across with the link.
            ->assertSet('patientId', $original->patient_id)
            ->set('date', $date)
            ->set('startTime', $slot)
            ->call('save')
            ->assertSet('failed', false);

        $original->refresh();

        $this->assertNotNull($original->rebooked_booking_id);

        Livewire::actingAs($this->owner)
            ->test(Rebooking::class)
            ->assertViewHas('bookings', fn ($bookings) => $bookings->isEmpty());
    }

    public function test_a_stale_rebooking_link_does_not_block_the_booking(): void
    {
        // Never postponed, so not awaiting anything.
        $booking = $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)
            ->test(NewBooking::class, ['rebookingFor' => $booking->id])
            ->assertSet('rebookingFor', null)
            ->assertSet('failed', true);
    }

    public function test_another_clinics_booking_is_never_linkable(): void
    {
        $other = $this->otherClinic();

        $stranger = Booking::factory()->forClinic($other)
            ->cancelled(CancelReason::EMERGENCY)
            ->at($this->today->copy()->setTimeFromTimeString('11:00'))->create();

        Livewire::actingAs($this->owner)
            ->test(NewBooking::class, ['rebookingFor' => $stranger->id])
            ->assertSet('rebookingFor', null)
            ->assertSet('patientId', null);
    }

    /**
     * The next day and time the clinic can actually take a booking. The clinic
     * is closed on some weekdays, so "tomorrow" is not a safe assumption.
     *
     * @return array{0: string, 1: string} date, start time
     */
    private function nextFreeSlot(): array
    {
        $visitType = $this->clinic->visitTypes()->active()->firstOrFail();

        for ($offset = 1; $offset <= 14; $offset++) {
            $date = $this->today->copy()->addDays($offset);

            $availability = app(SlotAvailabilityService::class)->for($this->clinic, $date, $visitType);

            foreach ($availability->slots as $slot) {
                if ($slot->isAvailable) {
                    return [$date->toDateString(), $slot->startAt->format('H:i')];
                }
            }
        }

        $this->fail('The clinic has no free slot in the next two weeks.');
    }
}
