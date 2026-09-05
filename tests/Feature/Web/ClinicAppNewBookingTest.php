<?php

namespace Tests\Feature\Web;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\PatientLocation;
use App\Livewire\App\NewBooking;
use App\Models\Booking;
use App\Models\Patient;
use App\Models\VisitType;
use App\Services\V1\Booking\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ClinicAppNewBookingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private VisitType $visitType;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday the demo clinic is open, early enough that the day still
        // has slots left.
        Carbon::setTestNow(Carbon::parse('2026-09-03 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        // A provisioned clinic starts with every day closed, so open the one
        // the tests book into.
        $schedule = $this->clinic->scheduleFor(DayOfWeek::THURSDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $this->visitType = $this->clinic->visitTypes()->active()->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->owner)->test(NewBooking::class);
    }

    /** The first bookable start time the service offers today. */
    private function firstFreeSlot(): string
    {
        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::now($this->clinic->timezone),
            $this->visitType,
        );

        foreach ($availability->slots as $slot) {
            if ($slot->isAvailable) {
                return $slot->startAt->format('H:i');
            }
        }

        $this->fail('The demo clinic has no free slot today.');
    }

    /*
    |--------------------------------------------------------------------------
    | Getting there
    |--------------------------------------------------------------------------
    */

    public function test_the_screen_needs_a_signed_in_account(): void
    {
        $this->get(route('app.bookings.new'))->assertRedirect(route('app.login'));
    }

    public function test_it_opens_on_today_with_a_visit_type_chosen(): void
    {
        $this->page()
            ->assertOk()
            ->assertSet('date', '2026-09-03')
            ->assertSet('visitTypeId', $this->visitType->id)
            ->assertSet('kind', BookingKind::NORMAL->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Booking a new patient
    |--------------------------------------------------------------------------
    */

    public function test_it_books_a_new_patient_into_a_free_slot(): void
    {
        $slot = $this->firstFreeSlot();

        $this->page()
            ->set('patientName', 'سلمى محمود')
            ->set('phone', '01001234501')
            ->set('age', '31')
            ->call('selectSlot', $slot)
            ->call('save')
            ->assertSet('failed', false)
            ->assertSet('notice', __('booking.created'));

        $booking = Booking::latest('id')->first();

        $this->assertNotNull($booking);
        $this->assertSame($this->clinic->id, $booking->clinic_id);
        $this->assertSame(BookingStatus::BOOKED, $booking->status);
        $this->assertSame($slot, $booking->start_at->format('H:i'));
        $this->assertSame('سلمى محمود', $booking->patient->name);
        $this->assertSame(31, $booking->patient->age);
    }

    public function test_a_saved_booking_hands_back_its_tracking_link(): void
    {
        $page = $this->page()
            ->set('patientName', 'هدى عادل')
            ->set('phone', '01001234502')
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('save');

        $booking = Booking::latest('id')->first();

        $page->assertSet('trackingUrl', $booking->trackingUrl());
    }

    public function test_the_form_is_cleared_for_the_next_patient_but_keeps_the_day(): void
    {
        $this->page()
            ->set('patientName', 'منى سيد')
            ->set('phone', '01001234503')
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('save')
            ->assertSet('patientName', '')
            ->assertSet('phone', '')
            ->assertSet('startTime', null)
            ->assertSet('date', '2026-09-03');
    }

    public function test_the_price_and_duration_are_snapshotted_from_the_visit_type(): void
    {
        $this->page()
            ->set('patientName', 'عبير')
            ->set('phone', '01001234504')
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('save');

        $booking = Booking::latest('id')->first();

        $this->assertSame($this->visitType->duration_minutes, $booking->duration_minutes);
        $this->assertSame(
            number_format((float) $this->visitType->price, 2, '.', ''),
            $booking->price,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Booking an existing patient
    |--------------------------------------------------------------------------
    */

    public function test_an_existing_patient_can_be_found_and_chosen(): void
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'نادية فؤاد',
        ]);

        $this->page()
            ->set('patientSearch', 'نادية')
            ->assertSee('نادية فؤاد')
            ->call('selectPatient', $patient->id)
            ->assertSet('patientId', $patient->id)
            ->assertSet('patientName', 'نادية فؤاد');
    }

    public function test_choosing_an_existing_patient_does_not_create_a_second_one(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
        $before = Patient::count();

        $this->page()
            ->call('selectPatient', $patient->id)
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('save')
            ->assertSet('failed', false);

        $this->assertSame($before, Patient::count());
        $this->assertSame($patient->id, Booking::latest('id')->first()->patient_id);
    }

    public function test_another_clinics_patient_is_never_searchable(): void
    {
        $other = $this->otherClinic();
        $theirs = Patient::factory()->create([
            'clinic_id' => $other->id,
            'name' => 'مريضة عيادة تانية',
        ]);

        $this->page()
            ->set('patientSearch', 'مريضة')
            ->assertDontSee($theirs->name);
    }

    /*
    |--------------------------------------------------------------------------
    | Emergencies
    |--------------------------------------------------------------------------
    */

    public function test_an_emergency_takes_no_slot(): void
    {
        $this->page()
            ->call('selectKind', BookingKind::EMERGENCY->value)
            ->assertSet('startTime', null)
            ->set('patientName', 'حالة طارئة')
            ->set('phone', '01001234505')
            ->call('save')
            ->assertSet('failed', false);

        $booking = Booking::latest('id')->first();

        $this->assertSame(BookingKind::EMERGENCY, $booking->booking_kind);
        $this->assertNull($booking->start_at);
    }

    public function test_an_emergency_already_in_the_clinic_joins_the_queue_immediately(): void
    {
        $this->page()
            ->call('selectKind', BookingKind::EMERGENCY->value)
            ->set('patientLocation', PatientLocation::INSIDE_CLINIC->value)
            ->set('patientName', 'حالة داخل العيادة')
            ->set('phone', '01001234506')
            ->call('save')
            ->assertSet('failed', false);

        $booking = Booking::latest('id')->first();

        $this->assertSame(BookingStatus::ARRIVED, $booking->status);
        $this->assertNotNull($booking->queue_entered_at);
    }

    public function test_switching_back_to_normal_clears_the_patient_location(): void
    {
        $this->page()
            ->call('selectKind', BookingKind::EMERGENCY->value)
            ->assertSet('patientLocation', PatientLocation::INSIDE_CLINIC->value)
            ->call('selectKind', BookingKind::NORMAL->value)
            ->assertSet('patientLocation', null);
    }

    /*
    |--------------------------------------------------------------------------
    | What the form refuses, and what the service refuses
    |--------------------------------------------------------------------------
    */

    public function test_a_normal_booking_needs_a_slot(): void
    {
        $this->page()
            ->set('patientName', 'بدون ميعاد')
            ->set('phone', '01001234507')
            ->call('save')
            ->assertHasErrors(['startTime' => 'required']);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_booking_needs_a_patient(): void
    {
        $this->page()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('save')
            ->assertHasErrors(['patientName', 'phone']);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_taken_slot_is_refused_by_the_service_not_the_form(): void
    {
        $slot = $this->firstFreeSlot();

        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 '.$slot, $this->clinic->timezone), $this->visitType->duration_minutes)
            ->create(['visit_type_id' => $this->visitType->id]);

        $this->page()
            ->set('patientName', 'متأخرة')
            ->set('phone', '01001234508')
            ->call('selectSlot', $slot)
            ->call('save')
            ->assertSet('failed', true)
            ->assertSet('notice', __('booking.slot_unavailable'));

        // The clash was caught by BookingService, so nothing extra was written.
        $this->assertSame(1, Booking::count());
    }
}
