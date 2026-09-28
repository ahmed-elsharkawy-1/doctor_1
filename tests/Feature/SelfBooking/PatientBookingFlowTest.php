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
    /**
     * Every price the patient is shown carries its currency.
     *
     * This screen is where somebody decides whether to pay, so a bare number
     * is worse here than anywhere else in the app — and this page was the only
     * surface printing one.
     */
    public function test_prices_on_the_booking_screen_carry_their_currency(): void
    {
        // Priced here rather than taken from the fixture: a free visit type
        // shows no price at all, and a test that silently skips proves
        // nothing about the thing it is named after.
        VisitType::whereKey($this->visitTypeId())->update(['price' => 400]);
        config(['clinic.self_booking.show_price' => true]);

        $html = $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->assertViewHas('stage', 'appointment')
            ->html();

        $this->assertStringContainsString('400 '.__('messages.currency'), $html);
    }

    /**
     * A returning patient's name survives a reload.
     *
     * The proof of the phone lives in the session; the name is a component
     * property, and a fresh mount resets it to ''. Without seeding it back
     * from the clinic's own record, coming back inside the verified window
     * landed on the slot screen with no name — where confirm() then failed
     * validation on a field that screen does not show. A dead end with
     * nothing to press.
     */
    public function test_a_returning_patients_name_survives_a_reload(): void
    {
        Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => self::E164,
            'name' => 'فاطمة عبد الرحمن',
        ]);

        $this->verified();

        // A fresh component, as a reload gives you.
        $page = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertViewHas('stage', 'appointment');

        $this->assertSame('فاطمة عبد الرحمن', $page->get('name'));

        $page->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm')
            ->assertSet('failed', false)
            ->assertViewHas('stage', 'done');
    }

    /**
     * A first-timer has no record to recover a name from, so they are asked
     * again rather than shown a screen whose only button refuses. The number
     * they already proved is still filled in.
     */
    public function test_a_first_timer_who_reloads_is_asked_for_their_name_again(): void
    {
        $this->verified();

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertViewHas('stage', 'details')
            ->assertSet('phone', self::E164);
    }

    /**
     * Off by default, and the clinics piloting this asked for that: a number
     * on a screen gets treated as a commitment, and they would rather discuss
     * cost at the desk. The figure is still stored and still snapshotted onto
     * the booking — this hides it, it does not stop charging for anything.
     */
    public function test_no_price_is_quoted_to_the_patient_by_default(): void
    {
        VisitType::whereKey($this->visitTypeId())->update(['price' => 400]);

        $html = $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->assertViewHas('stage', 'appointment')
            ->html();

        $this->assertStringNotContainsString('400', $html);
        $this->assertStringNotContainsString(__('messages.currency'), $html);

        // The visit still has its price on the record behind the screen.
        $this->assertSame(400.0, (float) VisitType::whereKey($this->visitTypeId())->value('price'));
    }

    /**
     * The final review before committing names each fact separately.
     */
    public function test_the_confirm_screen_reviews_day_time_and_duration(): void
    {
        $html = $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->assertViewHas('stage', 'appointment')
            ->html();

        foreach ([
            __('booking.self_booking.day'),
            __('booking.self_booking.slot'),
            __('booking.self_booking.expected_duration'),
        ] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /**
     * A refused code empties the boxes.
     *
     * The field behind them cannot be edited a character at a time, so leaving
     * a known-wrong code on screen only invites the patient to submit it again.
     */
    public function test_a_refused_code_empties_the_field(): void
    {
        $page = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->set('code', '0000')
            ->call('verifyCode');

        $page->assertSet('failed', true)
            ->assertViewHas('stage', 'code')
            ->assertSet('code', '')
            ->assertDispatched('code-cleared');
    }

    /**
     * Asking for a new code must empty the old one.
     *
     * The server clearing it is only half: `wire:model` is deferred, so the
     * browser keeps what was typed. Without the event the boxes still show the
     * expired code, and the patient submits it again.
     */
    public function test_asking_for_a_new_code_empties_the_field(): void
    {
        config(['clinic.self_booking.otp.resend_cooldown' => 0]);

        $page = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->set('code', '9999')
            ->call('resendCode');

        $page->assertSet('code', '')
            ->assertDispatched('code-cleared');
    }

    /**
     * The code screen is four drawn boxes over exactly one real field.
     *
     * Four inputs would break SMS autofill and paste, which only ever target a
     * single element — so the count matters, not just the look. A second input
     * appearing here is the regression this guards.
     */
    public function test_the_code_screen_has_one_field_behind_its_boxes(): void
    {
        $html = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->assertViewHas('stage', 'code')
            ->html();

        $start = (int) strpos($html, 'class="code-boxes"');
        $codeArea = substr($html, $start, 1400);

        $this->assertSame(1, substr_count($codeArea, '<input'), 'the code screen must hold exactly one input');
        $this->assertStringContainsString('code-entry', $codeArea);
        $this->assertSame(
            (int) config('clinic.self_booking.otp.length'),
            // The wrapper is `code-boxes`, which contains this needle too —
            // so the opening tag is matched, not the class name alone.
            substr_count($codeArea, '<div class="code-box'),
            'one drawn box per digit',
        );
    }

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
