<?php

namespace Tests\Feature\SelfBooking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\SlotHold;
use App\Models\VisitType;
use App\Services\Messaging\OtpSender;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Booking\SlotHoldService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingOtpSender;
use Tests\TestCase;

/**
 * A patient booking themselves, end to end — and the ways a tampered request
 * tries to skip the parts it does not like.
 *
 * Every test here runs as a guest. The component's own properties are whatever
 * the browser last sent, so the tests that matter most are the ones that set
 * them to something they should not be.
 */
class PatientBookingFlowTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    private const PHONE = '01012225521';

    private const E164 = '+201012225521';

    private RecordingOtpSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic->update([
            'slug' => 'dr-sara',
            'phone' => '01001234500',
            'self_booking_enabled' => true,
            'booking_window_days' => 7,
            'patient_booking_window_days' => 3,
        ]);

        foreach ([self::DAY, '2026-09-05', '2026-09-10'] as $date) {
            $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse($date)));
            $schedule->update(['is_open' => true]);

            if ($schedule->periods()->count() === 0) {
                $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
            }
        }

        $this->sender = new RecordingOtpSender;
        $this->app->instance(OtpSender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | The whole way through
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_books_themselves_from_start_to_finish(): void
    {
        $page = $this->verified();

        $page->assertViewHas('stage', 'appointment')
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm')
            ->assertSet('failed', false)
            ->assertViewHas('stage', 'done');

        $booking = Booking::firstOrFail();

        $this->assertSame(BookingSource::PATIENT_WEB, $booking->source);
        // Real on arrival — nothing at the clinic has to accept it first.
        $this->assertSame(BookingStatus::BOOKED, $booking->status);
        // There is no account behind a public page.
        $this->assertNull($booking->created_by);
        $this->assertSame(self::E164, $booking->patient->phone);
        $this->assertSame('فاطمة عبد الرحمن', $booking->patient->name);
    }

    public function test_the_finished_booking_offers_its_tracking_page(): void
    {
        $page = $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm');

        $page->assertSee(Booking::firstOrFail()->trackingUrl(), escape: false);
    }

    /** The clinic's own confirmation goes out exactly as it does for the secretary. */
    public function test_the_confirmation_message_is_queued(): void
    {
        $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm');

        $this->assertDatabaseHas('outbound_messages', [
            'clinic_id' => $this->clinic->id,
            'booking_id' => Booking::firstOrFail()->id,
            'template_key' => 'booking_confirmed',
        ]);

        $this->assertSame(1, OutboundMessage::count());
    }

    /** Verifying by message is consent to be messaged about the visit. */
    public function test_booking_this_way_records_whatsapp_consent(): void
    {
        $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm');

        $this->assertNotNull(Patient::firstOrFail()->whatsapp_opt_in_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Holding the slot
    |--------------------------------------------------------------------------
    */

    public function test_picking_a_time_claims_it(): void
    {
        $slot = $this->firstFreeSlot();

        $page = $this->verified()->call('selectSlot', $slot);

        $this->assertNotNull($page->get('holdToken'));
        $this->assertDatabaseHas('slot_holds', [
            'clinic_id' => $this->clinic->id,
            'source' => BookingSource::PATIENT_WEB->value,
        ]);
    }

    public function test_a_time_somebody_else_is_holding_is_refused_at_the_tap(): void
    {
        $slot = $this->firstFreeSlot();

        app(SlotHoldService::class)->hold(
            $this->clinic,
            $this->visitTypeId(),
            self::DAY,
            $slot,
        );

        $this->verified()
            ->call('selectSlot', $slot)
            ->assertSet('failed', true)
            ->assertSet('startTime', null)
            ->assertSet('holdToken', null)
            ->assertSet('notice', __('booking.slot_unavailable'));
    }

    public function test_the_claim_is_given_up_once_the_booking_is_made(): void
    {
        $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm');

        $this->assertSame(0, SlotHold::count());
    }

    public function test_a_lapsed_claim_loses_the_slot(): void
    {
        $slot = $this->firstFreeSlot();
        $page = $this->verified()->call('selectSlot', $slot);

        Carbon::setTestNow(Carbon::now()->addMinutes(
            (int) config('clinic.self_booking.hold_ttl_minutes') + 1,
        ));

        // Somebody else takes it while they were away.
        app(SlotHoldService::class)->hold($this->clinic, $this->visitTypeId(), self::DAY, $slot);

        $page->call('confirm')
            ->assertSet('failed', true)
            ->assertSet('notice', __('booking.slot_unavailable'));

        $this->assertSame(0, Booking::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Already has one
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_with_a_visit_still_to_come_is_shown_it_instead(): void
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => self::E164,
            'name' => 'فاطمة عبد الرحمن',
        ]);

        $existing = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 11:00', $this->clinic->timezone))
            ->create(['patient_id' => $patient->id]);

        $this->verified()
            ->assertViewHas('stage', 'upcoming')
            ->assertSee(__('booking.self_booking.upcoming_title'))
            ->assertSee($existing->trackingUrl(), escape: false);
    }

    public function test_a_cancelled_visit_does_not_stand_in_the_way(): void
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => self::E164,
        ]);

        Booking::factory()->forClinic($this->clinic)->cancelled()
            ->at(Carbon::parse(self::DAY.' 11:00', $this->clinic->timezone))
            ->create(['patient_id' => $patient->id]);

        $this->verified()->assertViewHas('stage', 'appointment');
    }

    public function test_booking_a_second_time_is_refused_even_if_the_screen_is_stale(): void
    {
        $page = $this->verified()->call('selectSlot', $this->firstFreeSlot());

        // Somebody books for the same number in between.
        $patient = Patient::where('phone', self::E164)->first()
            ?? Patient::factory()->create(['clinic_id' => $this->clinic->id, 'phone' => self::E164]);

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-05 11:00', $this->clinic->timezone))
            ->create(['patient_id' => $patient->id]);

        $page->call('confirm')
            ->assertSet('failed', true)
            ->assertSet('notice', __('booking.self_booking.already_booked'));
    }

    /**
     * A returning patient is usually booking the same thing again.
     */
    public function test_a_returning_patients_last_visit_type_is_pre_selected(): void
    {
        $followUp = VisitType::factory()->followUp()->create(['clinic_id' => $this->clinic->id]);

        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => self::E164,
        ]);

        Booking::factory()->forClinic($this->clinic)->done()
            ->at(Carbon::parse('2026-08-20 10:00', $this->clinic->timezone))
            ->create(['patient_id' => $patient->id, 'visit_type_id' => $followUp->id]);

        $this->verified()->assertSet('visitTypeId', $followUp->id);
    }

    /*
    |--------------------------------------------------------------------------
    | What a tampered request gets
    |--------------------------------------------------------------------------
    */

    /**
     * The one that matters most. `$step` is whatever the browser sent, so it
     * can never be what decides whether the phone was proved.
     */
    public function test_setting_the_step_by_hand_does_not_skip_verification(): void
    {
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->set('step', 4)
            ->assertViewHas('stage', 'overview')
            ->assertDontSee(__('booking.self_booking.confirm'));
    }

    public function test_booking_without_a_verified_number_is_refused(): void
    {
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->set('step', 4)
            ->set('name', 'مجهول')
            ->set('visitTypeId', $this->visitTypeId())
            ->set('date', self::DAY)
            ->set('startTime', $this->firstFreeSlot())
            ->call('confirm')
            ->assertSet('failed', true)
            ->assertSet('notice', __('patient.otp.not_verified'));

        $this->assertSame(0, Booking::count());
    }

    /**
     * SlotAvailabilityService only knows the clinic's own window, so day 6 of 7
     * looks perfectly bookable to it. This is the only thing standing between a
     * crafted request and an appointment outside the patient window.
     */
    public function test_a_day_past_the_patient_window_is_refused(): void
    {
        $this->verified()
            ->set('date', '2026-09-10')
            ->set('startTime', '09:00')
            ->call('confirm')
            ->assertSet('failed', true);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_type_the_clinic_keeps_to_itself_cannot_be_booked(): void
    {
        $private = VisitType::factory()->notSelfBookable()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'عملية كبرى',
        ]);

        $this->verified()
            ->set('visitTypeId', $private->id)
            ->set('startTime', '09:00')
            ->call('confirm')
            ->assertSet('failed', true);

        $this->assertSame(0, Booking::count());
    }

    /**
     * The operator switching self-booking off takes the page away, mid-flow
     * included: the clinic is re-resolved on every request, and a clinic that
     * does not offer this has no booking page rather than a disabled one.
     *
     * Abrupt, and rare enough to be worth it — the alternative is a page that
     * stays open on a promise the clinic has withdrawn.
     */
    public function test_switching_self_booking_off_mid_flow_stops_the_booking(): void
    {
        $page = $this->verified()->call('selectSlot', $this->firstFreeSlot());

        $this->clinic->update(['self_booking_enabled' => false]);

        $page->call('confirm')->assertStatus(404);

        $this->assertSame(0, Booking::count());
    }

    /**
     * The finished booking is matched against the verified number, so pointing
     * the id at somebody else's appointment shows nothing.
     */
    public function test_another_patients_booking_cannot_be_read_from_the_done_screen(): void
    {
        $theirs = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 12:00', $this->clinic->timezone))
            ->create();

        $this->verified()
            ->set('confirmedBookingId', $theirs->id)
            ->assertViewHas('stage', 'appointment')
            ->assertDontSee($theirs->patient->code);
    }

    /*
    |--------------------------------------------------------------------------
    | Changing their mind
    |--------------------------------------------------------------------------
    */

    public function test_changing_the_number_drops_the_proof_and_the_claim(): void
    {
        $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('changeNumber')
            ->assertViewHas('stage', 'details');

        $this->assertSame(0, SlotHold::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** A page that has been carried as far as a proved phone number. */
    private function verified(): Testable
    {
        return Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->set('code', $this->sender->lastCode())
            ->call('verifyCode');
    }

    private function visitTypeId(): int
    {
        return (int) $this->clinic->visitTypes()->selfBookable()->value('id');
    }

    private function firstFreeSlot(): string
    {
        $visitType = $this->clinic->visitTypes()->selfBookable()->firstOrFail();

        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse(self::DAY, $this->clinic->timezone),
            $visitType,
        );

        foreach ($availability->slots as $slot) {
            if ($slot->isAvailable) {
                return $slot->startAt->format('H:i');
            }
        }

        $this->fail('The clinic has no free slot on the test day.');
    }
}
