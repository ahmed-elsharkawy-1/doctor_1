<?php

namespace Tests\Feature\SelfBooking;

use App\Enums\BookingSource;
use App\Enums\DayOfWeek;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Models\Patient;
use App\Models\SlotHold;
use App\Services\V1\Booking\PatientBookingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Booking with phone verification switched off.
 *
 * Temporary, and only because Meta will not issue an authentication template
 * for this account yet. The point of these tests is that switching it off
 * removes *one* thing — the proof — and nothing else: the number is still
 * parsed and normalised, it is still the identity the booking is filed under,
 * a second booking is still refused, and the code screen is still unreachable.
 *
 * If any of these start failing, the skip has grown beyond what it was meant
 * to be.
 */
class BookingWithoutOtpTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    private const PHONE = '01012225521';

    private const E164 = '+201012225521';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic->update([
            'slug' => 'dr-sara',
            'self_booking_enabled' => true,
            'patient_booking_window_days' => 3,
        ]);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);

        if ($schedule->periods()->count() === 0) {
            $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
        }

        config(['clinic.self_booking.require_otp' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | The shortened flow
    |--------------------------------------------------------------------------
    */

    public function test_entering_a_name_and_number_goes_straight_to_choosing_a_time(): void
    {
        $this->details()->assertViewHas('stage', 'appointment');
    }

    public function test_no_verification_is_issued_at_all(): void
    {
        $this->details();

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    /** Two screens, not three — the counter must not promise one that never comes. */
    public function test_the_step_counter_drops_to_two(): void
    {
        $this->details()
            ->assertViewHas('stepCount', 2)
            ->assertViewHas('stepNumber', 2);
    }

    public function test_a_booking_can_be_completed(): void
    {
        $this->details()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm')
            ->assertSet('failed', false)
            ->assertViewHas('stage', 'done');

        $booking = Booking::firstOrFail();

        $this->assertSame(BookingSource::PATIENT_WEB, $booking->source);
        $this->assertNull($booking->created_by);
        $this->assertSame(self::E164, $booking->patient->phone);
    }

    /*
    |--------------------------------------------------------------------------
    | What must NOT have been skipped
    |--------------------------------------------------------------------------
    */

    /** The number is the identity the visit is filed under, verified or not. */
    public function test_a_malformed_number_is_still_refused(): void
    {
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', 'not-a-number')
            ->call('sendCode')
            ->assertSet('failed', true)
            ->assertViewHas('stage', 'details');

        $this->assertSame(0, Booking::count());
    }

    public function test_the_number_is_still_normalised(): void
    {
        $this->details()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm');

        // Typed nationally, stored E.164 — the same shape the verified flow
        // would have stored, so one patient is never two records.
        $this->assertSame(self::E164, Patient::firstOrFail()->phone);
    }

    public function test_a_second_booking_is_still_refused(): void
    {
        $this->details()
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm')
            ->assertViewHas('stage', 'done');

        // Same number, fresh browser.
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->assertViewHas('stage', 'upcoming');

        $this->assertSame(1, Booking::count());
    }

    /** $step is whatever the browser last sent, so this is a guard. */
    public function test_the_code_screen_cannot_be_reached_by_posting_a_step(): void
    {
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->set('step', 3)
            ->assertViewHas('stage', 'details');
    }

    /** A clinic that never switched self-booking on is still closed. */
    public function test_a_closed_clinic_is_still_closed(): void
    {
        $this->clinic->update(['self_booking_enabled' => false]);

        $this->get('/'.$this->clinic->slug.'/'.config('clinic.self_booking.path'))
            ->assertNotFound();
    }

    /**
     * Going back from the slot screen must give the slot up.
     *
     * Otherwise a patient who changes their mind leaves a time blocked behind
     * them for the length of the hold, and nobody else can take it.
     */
    public function test_going_back_from_the_slot_screen_releases_the_hold(): void
    {
        $page = $this->details()->call('selectSlot', $this->firstFreeSlot());

        $this->assertSame(1, SlotHold::count());

        $page->call('changeNumber')
            ->assertViewHas('stage', 'details')
            ->assertSet('holdToken', null);

        $this->assertSame(0, SlotHold::count());
    }

    /*
    |--------------------------------------------------------------------------
    | The switch itself
    |--------------------------------------------------------------------------
    */

    /** Off is a deliberate act, and on is the default everywhere. */
    public function test_verification_is_required_by_default(): void
    {
        config(['clinic.self_booking.require_otp' => true]);

        $this->assertTrue(app(PatientBookingService::class)->requiresOtp());

        $this->details()->assertViewHas('stage', 'code');
    }

    /** The trust path refuses to run while verification is switched on. */
    public function test_the_trust_path_refuses_while_verification_is_on(): void
    {
        config(['clinic.self_booking.require_otp' => true]);

        $this->expectException(\LogicException::class);

        app(PatientBookingService::class)->acceptPhoneUnverified($this->clinic, self::PHONE);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function details(): Testable
    {
        return Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode');
    }

    private function firstFreeSlot(): string
    {
        $visitType = $this->clinic->visitTypes()->selfBookable()->active()->first();

        $availability = app(PatientBookingService::class)
            ->availability($this->clinic, $visitType, self::DAY);

        foreach ($availability->slots as $slot) {
            if ($slot->isAvailable) {
                return $slot->startAt->format('H:i');
            }
        }

        $this->fail('no free slot on '.self::DAY);
    }
}
