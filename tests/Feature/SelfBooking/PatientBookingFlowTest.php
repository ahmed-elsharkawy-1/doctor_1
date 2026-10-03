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
use App\Services\Messaging\ZadxOtpSender;
use App\Services\V1\Booking\PatientBookingService;
use App\Services\V1\Booking\Slot;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Booking\SlotHoldService;
use App\Support\HeldSlotSession;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

    public function test_with_whatsapp_off_no_confirmation_is_queued(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);

        $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm')
            ->assertSet('failed', false);

        $this->assertSame(1, Booking::count());
        $this->assertSame(0, OutboundMessage::count());
    }

    /** The page must not promise updates that will never come. */
    public function test_with_whatsapp_off_the_page_promises_no_whatsapp_updates(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);

        $details = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])->call('start')->html();

        $this->assertStringContainsString(e(__('booking.self_booking.phone_hint_no_whatsapp', ['channel' => app(PatientBookingService::class)->otpChannel()])), $details);

        $done = $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm')
            ->html();

        $this->assertStringContainsString(e(__('booking.self_booking.done_lead_no_whatsapp')), $done);
        $this->assertStringNotContainsString(e(__('booking.self_booking.done_lead')), $done);
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

        $this->verified()->call('selectSlot', $slot);

        // In the session rather than on the component, so it survives a
        // reload — and so the token that can book the slot never travels to
        // the browser at all.
        $this->assertNotNull(app(HeldSlotSession::class)->tokenFor($this->clinic));

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
            ->assertSet('notice', __('booking.slot_unavailable'));

        $this->assertNull(app(HeldSlotSession::class)->tokenFor($this->clinic));
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
    public function test_the_confirm_screen_reviews_the_day_and_the_time(): void
    {
        $html = $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->assertViewHas('stage', 'appointment')
            ->html();

        foreach ([
            __('booking.self_booking.day'),
            __('booking.self_booking.slot'),
        ] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // How long the visit takes is the clinic's business, and not something
        // the patient chose — on the screen where they check what they are
        // agreeing to, it is one more number to read past.
        $this->assertStringNotContainsString(__('booking.self_booking.expected_duration'), $html);
    }

    /**
     * The notice has to carry the deadline, since it is the one thing on this
     * screen the patient can act on — but as a reassurance about what is being
     * held for them, not as a warning about losing it.
     */
    public function test_the_hold_notice_names_the_deadline(): void
    {
        $minutes = (int) config('clinic.self_booking.hold_ttl_minutes');

        $this->verified()
            ->call('selectSlot', $this->firstFreeSlot())
            ->assertSee(__('booking.self_booking.hold_note', [
                'minutes' => trans_choice('booking.self_booking.minutes_count', $minutes),
            ]));
    }

    /** «٥ دقائق», not «٥ دقيقة» — the count decides the form in Arabic. */
    public function test_the_hold_notice_counts_minutes_in_correct_arabic(): void
    {
        $this->assertSame('دقيقة واحدة', trans_choice('booking.self_booking.minutes_count', 1, [], 'ar'));
        $this->assertSame('دقيقتين', trans_choice('booking.self_booking.minutes_count', 2, [], 'ar'));
        $this->assertSame('5 دقائق', trans_choice('booking.self_booking.minutes_count', 5, [], 'ar'));
        $this->assertSame('15 دقيقة', trans_choice('booking.self_booking.minutes_count', 15, [], 'ar'));
    }

    /**
     * A patient reading an Arabic page must not be told "The name field is
     * required" — and must not be told it about a field called `startTime`.
     */
    public function test_validation_errors_reach_the_patient_in_arabic(): void
    {
        app()->setLocale('ar');

        $html = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', '')
            ->set('phone', '')
            ->call('sendCode')
            ->html();

        $this->assertStringContainsString(__('validation.required', [
            'attribute' => __('validation.attributes.name'),
        ]), $html);

        $this->assertStringNotContainsString('field is required', $html);
    }

    /**
     * Back from the code screen, then "send" again for the same number.
     *
     * The code already on the phone still works, so the patient is taken back
     * to it — no second message, no "wait sixty seconds".
     */
    public function test_going_back_and_sending_again_returns_to_the_code_already_sent(): void
    {
        $page = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->call('back')
            ->call('sendCode')
            ->assertSet('failed', false)
            ->assertSet('notice', __('patient.otp.still_valid'))
            ->assertSet('step', 3);

        $this->assertCount(1, $this->sender->sent);

        $page->set('code', $this->sender->lastCode())
            ->call('verifyCode')
            ->assertSet('failed', false)
            ->assertViewHas('stage', 'appointment');
    }

    /** "Send again" on the code screen is the way out of a lost message — always a new code. */
    public function test_the_resend_button_still_sends_a_new_code(): void
    {
        config(['clinic.self_booking.otp.resend_cooldown' => 0]);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->call('resendCode')
            ->assertSet('notice', __('patient.otp.sent'));

        $this->assertCount(2, $this->sender->sent);
    }

    /**
     * The SMS provider refusing to send is a notice on the page, not a 500.
     *
     * Run through the real ZADX driver with its real out-of-credit answer —
     * the failure this was written for — so the whole chain from their 402 to
     * the patient's screen is the thing under test.
     */
    public function test_a_code_that_cannot_be_sent_is_explained_on_the_page(): void
    {
        $this->useZadxThatIsOutOfCredit();

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->assertOk()
            ->assertSet('failed', true)
            ->assertSet('notice', __('patient.otp.send_failed'))
            // Still on the details step: there is no code to type.
            ->assertSet('step', 2);
    }

    public function test_a_resend_that_cannot_be_sent_is_explained_on_the_page(): void
    {
        config(['clinic.self_booking.otp.resend_cooldown' => 0]);

        $page = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode');

        $this->useZadxThatIsOutOfCredit();

        $page->call('resendCode')
            ->assertOk()
            ->assertSet('failed', true)
            ->assertSet('notice', __('patient.otp.send_failed'))
            ->assertSet('step', 3);
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

    /*
    |--------------------------------------------------------------------------
    | Holding a slot across a reload
    |--------------------------------------------------------------------------
    */

    /**
     * A hold blocks everybody except its holder, and a reload does not make
     * the patient somebody else.
     *
     * The token lived only on the component, so a reload arrived with none —
     * and a request holding no token is told, correctly, that every live hold
     * belongs to someone else. The patient's own claim struck out their own
     * slot, and stayed struck out until it lapsed.
     */
    public function test_a_reload_does_not_hide_the_slot_the_patient_is_holding(): void
    {
        Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => self::E164,
            'name' => 'فاطمة عبد الرحمن',
        ]);

        $slot = $this->firstFreeSlot();

        $this->verified()->call('selectSlot', $slot);

        // A fresh component, as a reload gives you.
        $reloaded = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertViewHas('stage', 'appointment');

        $this->assertTrue(
            $this->slotIsFree($reloaded->viewData('availability')->slots, $slot),
            "the patient's own held slot must still be bookable after a reload",
        );
    }

    /** The other half of the same rule: it stays blocked for everybody else. */
    public function test_a_hold_still_blocks_a_different_browser(): void
    {
        $slot = $this->firstFreeSlot();

        $this->verified()->call('selectSlot', $slot);

        // A second visitor, with nothing of the first one's session.
        session()->flush();

        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse(self::DAY, $this->clinic->timezone),
            $this->clinic->visitTypes()->selfBookable()->firstOrFail(),
        );

        $this->assertFalse(
            $this->slotIsFree($availability->slots, $slot),
            'somebody else must still see the held slot as taken',
        );
    }

    /**
     * Picking a different slot after a reload has to free the first one, which
     * only works if the restored token is the same claim rather than a new one.
     */
    public function test_a_reload_then_a_new_choice_moves_the_hold_rather_than_adding_one(): void
    {
        Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => self::E164,
            'name' => 'فاطمة عبد الرحمن',
        ]);

        $first = $this->firstFreeSlot();

        $this->verified()->call('selectSlot', $first);

        $second = collect($this->freeSlotsIn('00:00', '23:59'))
            ->first(fn (string $time): bool => $time !== $first);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('selectSlot', $second);

        $this->assertSame(
            1,
            SlotHold::query()->live()->where('clinic_id', $this->clinic->id)->count(),
            'the hold must move, not multiply',
        );
    }

    /** @param  list<Slot>  $slots */
    private function slotIsFree(array $slots, string $time): bool
    {
        foreach ($slots as $slot) {
            if ($slot->startAt->format('H:i') === $time) {
                return $slot->isAvailable;
            }
        }

        $this->fail("no slot at {$time}");
    }

    /*
    |--------------------------------------------------------------------------
    | Which day starts selected
    |--------------------------------------------------------------------------
    */

    /**
     * A reload is not a fresh start for the session — it is verified already,
     * so the page opens straight on the appointment screen without passing
     * through the step that picks a day. Today would be selected with nothing
     * under it, and on a closed or fully booked today that reads as a clinic
     * with no appointments at all.
     */
    public function test_a_reload_stays_on_the_first_day_with_room(): void
    {
        $this->closeDay(self::DAY);

        // Known to the clinic, so the reload lands on the appointment screen
        // rather than being asked for a name again.
        Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => self::E164,
            'name' => 'فاطمة عبد الرحمن',
        ]);

        $this->verified()->assertSet('date', '2026-09-05');

        // A fresh component, as a reload gives you.
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertViewHas('stage', 'appointment')
            ->assertSet('date', '2026-09-05');
    }

    /** The same rule before verification, so the read-only week agrees. */
    public function test_the_opening_screen_points_at_the_first_day_with_room(): void
    {
        $this->closeDay(self::DAY);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertViewHas('stage', 'overview')
            ->assertSet('date', '2026-09-05');
    }

    /**
     * Nothing bookable anywhere in the window still has to select something,
     * and today is the honest answer — the screen then says the day is empty
     * rather than silently showing another clinic's week.
     */
    public function test_a_week_with_no_room_at_all_falls_back_to_today(): void
    {
        foreach ([self::DAY, '2026-09-04', '2026-09-05'] as $date) {
            $this->closeDay($date);
        }

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertSet('date', self::DAY);
    }

    /**
     * How long a visit takes decides how many fit, so the count under every
     * day has to follow the choice. Worth pinning because the strip is now
     * built once per request and handed to two callers.
     */
    public function test_the_count_under_each_day_follows_the_visit_type(): void
    {
        $types = $this->clinic->visitTypes()->selfBookable()->orderBy('duration_minutes')->get();

        $short = $types->first();
        $long = $types->last();

        $this->assertNotSame(
            $short->duration_minutes,
            $long->duration_minutes,
            'this clinic needs two self-bookable types of different lengths',
        );

        $page = $this->verified();

        $page->call('selectVisitType', $long->id);
        $withLong = array_sum(array_column($page->viewData('days'), 'available_count'));

        $page->call('selectVisitType', $short->id);
        $withShort = array_sum(array_column($page->viewData('days'), 'available_count'));

        $this->assertGreaterThan($withLong, $withShort, 'shorter visits must fit more often');
    }

    private function closeDay(string $date): void
    {
        $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse($date)))
            ->update(['is_open' => false]);
    }

    /*
    |--------------------------------------------------------------------------
    | Which stretch of the day is open
    |--------------------------------------------------------------------------
    */

    /**
     * A page with every stretch shut shows no slots at all, and reads as a
     * finished screen rather than one waiting to be opened.
     */
    public function test_the_first_stretch_with_a_free_slot_opens_by_itself(): void
    {
        $page = $this->verified();

        $this->assertNotSame([], $page->viewData('slotGroups'), 'the test day must be grouped at all');
        $page->assertSet('openGroup', 1);
    }

    /** Shutting it has to stick, or the next render undoes the tap. */
    public function test_a_stretch_the_patient_closes_stays_closed(): void
    {
        $this->verified()
            ->call('toggleGroup', 1)
            ->assertSet('openGroup', null)
            // A render with nothing else changing must not reopen it.
            ->call('$refresh')
            ->assertSet('openGroup', null);
    }

    public function test_opening_another_stretch_closes_the_first(): void
    {
        $this->verified()
            ->call('toggleGroup', 2)
            ->assertSet('openGroup', 2);
    }

    /**
     * Opening a stretch with nothing left in it would answer the patient's
     * only question with a row of struck-through times.
     */
    public function test_a_fully_booked_first_stretch_is_passed_over(): void
    {
        $this->reshapeDay('09:00', '10:00', '11:00', '14:00');

        $other = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => '+201119998877',
        ]);

        foreach ($this->freeSlotsIn('09:00', '10:00') as $time) {
            Booking::factory()->forClinic($this->clinic)
                ->at(Carbon::parse(self::DAY.' '.$time, $this->clinic->timezone))
                ->create(['patient_id' => $other->id]);
        }

        $page = $this->verified();

        $groups = $page->viewData('slotGroups');

        $this->assertFalse($groups[0]->hasAnythingFree(), 'the morning must be full for this to test anything');
        $page->assertSet('openGroup', 2);
    }

    /** A different day is a different set of stretches. */
    public function test_choosing_another_day_opens_that_days_first_stretch(): void
    {
        $this->verified()
            ->call('toggleGroup', 1)
            ->assertSet('openGroup', null)
            ->call('selectDay', '2026-09-05')
            ->assertSet('openGroup', 1);
    }

    /**
     * Somebody else taking the last slot in the open stretch must not shut it
     * and open another under the patient's finger.
     */
    public function test_the_open_stretch_does_not_move_when_the_day_changes_around_it(): void
    {
        $this->reshapeDay('09:00', '10:00', '11:00', '14:00');

        $page = $this->verified()->assertSet('openGroup', 1);

        $other = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => '+201119998877',
        ]);

        foreach ($this->freeSlotsIn('09:00', '10:00') as $time) {
            Booking::factory()->forClinic($this->clinic)
                ->at(Carbon::parse(self::DAY.' '.$time, $this->clinic->timezone))
                ->create(['patient_id' => $other->id]);
        }

        $page->call('$refresh')->assertSet('openGroup', 1);
    }

    /** Replaces the test day's single period with two. */
    private function reshapeDay(string $from, string $to, string $secondFrom, string $secondTo): void
    {
        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));

        $schedule->periods()->delete();
        $schedule->periods()->create(['start_time' => $from, 'end_time' => $to]);
        $schedule->periods()->create(['start_time' => $secondFrom, 'end_time' => $secondTo]);
    }

    /** @return list<string> the free start times inside a window, as H:i */
    private function freeSlotsIn(string $from, string $to): array
    {
        $visitType = $this->clinic->visitTypes()->selfBookable()->firstOrFail();

        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse(self::DAY, $this->clinic->timezone),
            $visitType,
        );

        $times = [];

        foreach ($availability->slots as $slot) {
            $at = $slot->startAt->format('H:i');

            if ($slot->isAvailable && $at >= $from && $at < $to) {
                $times[] = $at;
            }
        }

        return $times;
    }

    private function useZadxThatIsOutOfCredit(): void
    {
        config([
            'services.zadx.base_url' => 'https://example.test/api/v1',
            'services.zadx.api_key' => 'pk_test',
            'services.zadx.api_secret' => 'sk_test',
        ]);

        Http::fake(['*' => Http::response(
            ['error' => ['code' => 'quota_exhausted', 'message' => 'Out of credits']],
            402,
        )]);

        $this->app->instance(OtpSender::class, new ZadxOtpSender);
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
