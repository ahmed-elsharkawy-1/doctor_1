<?php

namespace Tests\Feature\SelfBooking;

use App\Enums\ApiErrorCode;
use App\Enums\DayOfWeek;
use App\Enums\OnlineBookingBlockReason;
use App\Exceptions\ApiException;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Models\Patient;
use App\Services\Messaging\OtpSender;
use App\Services\V1\Booking\PatientBookingService;
use App\Services\V1\Booking\SlotAvailabilityService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingOtpSender;
use Tests\TestCase;

/**
 * A blocked patient on the public page: told why and pointed at the clinic,
 * after their number is proved — and refused by the service if the page is
 * skipped.
 */
class OnlineBookingBlockedFlowTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    private const PHONE = '01012225521';

    private const E164 = '+201012225521';

    private RecordingOtpSender $sender;

    private Patient $patient;

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

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);

        if ($schedule->periods()->count() === 0) {
            $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
        }

        $this->patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'فاطمة عبد الرحمن',
            'phone' => self::E164,
            'online_booking_blocked_at' => Carbon::parse('2026-09-01 07:00'),
            'online_booking_block_reason' => OnlineBookingBlockReason::NO_SHOWS,
        ]);

        $this->sender = new RecordingOtpSender;
        $this->app->instance(OtpSender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

        $this->fail('No free slot.');
    }

    public function test_a_blocked_patient_is_told_why_once_their_number_is_proved(): void
    {
        $this->verified()
            ->assertViewHas('stage', 'blocked')
            ->assertSee(__('booking.self_booking.blocked_title'))
            ->assertSee(__('booking.self_booking.blocked_lead'))
            ->assertDontSee(__('booking.self_booking.appointment_title'));
    }

    /** A block the clinic set by hand is not blamed on no-shows. */
    public function test_a_hand_block_is_not_explained_as_no_shows(): void
    {
        $this->patient->update(['online_booking_block_reason' => OnlineBookingBlockReason::MANUAL]);

        $this->verified()
            ->assertViewHas('stage', 'blocked')
            ->assertSee(__('booking.self_booking.blocked_lead_manual'))
            ->assertDontSee(__('booking.self_booking.blocked_lead'));
    }

    /** Before the code, the page says nothing — typing a number must not reveal it is blocked. */
    public function test_nothing_is_said_before_the_number_is_proved(): void
    {
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->assertViewHas('stage', 'code')
            ->assertDontSee(__('booking.self_booking.blocked_title'));
    }

    public function test_the_blocked_screen_offers_the_clinic(): void
    {
        $this->verified()
            ->assertSee(__('booking.self_booking.contact_clinic'))
            ->assertSee('tel:', escape: false);
    }

    public function test_tampering_past_the_screen_cannot_book(): void
    {
        $page = $this->verified();

        $page->set('visitTypeId', (int) $this->clinic->visitTypes()->selfBookable()->value('id'))
            ->set('date', self::DAY)
            ->call('selectSlot', $this->firstFreeSlot())
            ->assertSet('failed', true)
            ->call('confirm')
            ->assertSet('failed', true);

        $this->assertSame(0, Booking::count());
    }

    public function test_the_service_refuses_a_blocked_patient_with_its_own_code(): void
    {
        $this->verified();

        try {
            app(PatientBookingService::class)->confirm(
                $this->clinic,
                'فاطمة عبد الرحمن',
                (int) $this->clinic->visitTypes()->selfBookable()->value('id'),
                self::DAY,
                $this->firstFreeSlot(),
            );
            $this->fail('A blocked patient booked.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::ONLINE_BOOKING_BLOCKED, $e->errorCode);
        }

        $this->assertSame(0, Booking::count());
    }

    /** A booking they already had is kept, and the page shows it as before. */
    public function test_a_blocked_patient_still_sees_the_booking_they_have(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 11:00', 'Africa/Cairo'))
            ->create(['patient_id' => $this->patient->id]);

        $this->verified()->assertViewHas('stage', 'upcoming');
    }

    public function test_a_lifted_block_books_as_normal(): void
    {
        $this->patient->update(['online_booking_blocked_at' => null, 'online_booking_block_reason' => null]);

        $this->verified()
            ->assertViewHas('stage', 'appointment')
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('confirm')
            ->assertViewHas('stage', 'done');
    }

    /** Somebody else's block is not this number's. */
    public function test_a_new_number_books_as_normal(): void
    {
        $this->patient->update(['phone' => '+201099999999']);

        $this->verified()->assertViewHas('stage', 'appointment');
    }

    public function test_with_verification_off_the_screen_follows_the_number(): void
    {
        config(['clinic.self_booking.require_otp' => false]);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->assertViewHas('stage', 'blocked');
    }
}
