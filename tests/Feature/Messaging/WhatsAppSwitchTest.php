<?php

namespace Tests\Feature\Messaging;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Livewire\App\Messages;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\User;
use App\Services\V1\Queue\BookingStatusService;
use Database\Seeders\MessageTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * WhatsApp switched off for one clinic.
 *
 * A clinic piloting the app must be able to work its whole day without a
 * single message reaching a patient, and get WhatsApp back the moment it is
 * switched on. Off means nothing is sent and nothing is recorded as sent;
 * cancelling a day is still a clinic's job, so it still cancels.
 */
class WhatsAppSwitchTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-08-08';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '14:00']);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | The switch itself
    |--------------------------------------------------------------------------
    */

    /** Existing clinics and new ones keep WhatsApp until somebody turns it off. */
    public function test_a_clinic_has_whatsapp_unless_it_is_switched_off(): void
    {
        $this->assertTrue($this->clinic->fresh()->whatsapp_enabled);
        $this->assertTrue(Clinic::factory()->create()->fresh()->whatsapp_enabled);
    }

    public function test_the_admin_panel_switches_it(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
        // The form requires these; the test clinic is made without them.
        $this->clinic->update(['slug' => 'dr-test', 'phone' => '+201001234567']);

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->assertFormFieldExists('whatsapp_enabled')
            ->fillForm(['whatsapp_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->clinic->fresh()->whatsapp_enabled);
    }

    /** It is the platform's to decide, not the clinic's. */
    public function test_the_clinic_cannot_switch_it_from_its_own_settings(): void
    {
        $this->whatsappOff();
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.settings.general'), [
            'booking_window_days' => 10,
            'whatsapp_enabled' => true,
        ])->assertOk();

        $this->assertFalse($this->clinic->fresh()->whatsapp_enabled);
    }

    /** The mobile app is told, so it can hide what will not work. */
    public function test_the_app_is_told_on_launch(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->getJson(route('api.v1.bootstrap'))->assertJsonPath('data.clinic.whatsapp_enabled', true);

        $this->whatsappOff();

        $this->getJson(route('api.v1.bootstrap'))->assertJsonPath('data.clinic.whatsapp_enabled', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Automatic messages
    |--------------------------------------------------------------------------
    */

    public function test_finishing_a_visit_sends_no_thank_you_when_off(): void
    {
        $this->whatsappOff();
        $booking = $this->bookingAt('09:00');
        $booking->update(['status' => BookingStatus::WITH_DOCTOR]);

        $done = app(BookingStatusService::class)->complete($booking->fresh());

        $this->assertSame(BookingStatus::DONE, $done->status);
        $this->assertSame(0, OutboundMessage::count());
        Queue::assertNothingPushed();
    }

    public function test_finishing_a_visit_still_thanks_the_patient_when_on(): void
    {
        $booking = $this->bookingAt('09:00');
        $booking->update(['status' => BookingStatus::WITH_DOCTOR]);

        app(BookingStatusService::class)->complete($booking->fresh());

        $this->assertSame(1, OutboundMessage::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Messages staff send
    |--------------------------------------------------------------------------
    */

    public function test_the_app_is_offered_only_the_cancellation_when_off(): void
    {
        $this->harmlessTemplate();
        $this->whatsappOff();
        Sanctum::actingAs($this->secretary);

        $keys = array_column($this->getJson(route('api.v1.message-templates.index'))->json('data.items'), 'key');

        $this->assertSame(['day_cancelled'], $keys);
    }

    public function test_a_broadcast_is_refused_when_off(): void
    {
        $template = $this->harmlessTemplate();
        $this->whatsappOff();
        $booking = $this->bookingAt('09:00');
        Sanctum::actingAs($this->secretary);

        $this->postJson(route('api.v1.broadcasts.store'), ['template_key' => $template, 'date' => self::DAY])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::WHATSAPP_DISABLED->value);

        $this->assertSame(0, OutboundMessage::count());
        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_message_to_one_patient_is_refused_when_off(): void
    {
        $template = $this->harmlessTemplate();
        $this->whatsappOff();
        $booking = $this->bookingAt('09:00');
        Sanctum::actingAs($this->secretary);

        $this->postJson(route('api.v1.bookings.message', $booking), ['template_key' => $template])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::WHATSAPP_DISABLED->value);

        $this->assertSame(0, OutboundMessage::count());
    }

    /** Cancelling the day is the clinic's job; only the message is dropped. */
    public function test_cancelling_the_day_still_cancels_when_off(): void
    {
        $this->whatsappOff();
        $first = $this->bookingAt('09:00');
        $second = $this->bookingAt('10:00');
        Sanctum::actingAs($this->secretary);

        $this->postJson(route('api.v1.broadcasts.store'), ['template_key' => 'day_cancelled', 'date' => self::DAY])
            ->assertOk()
            ->assertJsonPath('data.cancelled_count', 2)
            ->assertJsonPath('data.sent_count', 0)
            ->assertJsonPath('data.whatsapp_enabled', false)
            ->assertJsonPath('data.skipped.0.reason', 'whatsapp_disabled');

        $this->assertSame(BookingStatus::CANCELLED, $first->fresh()->status);
        $this->assertSame(BookingStatus::CANCELLED, $second->fresh()->status);
        $this->assertSame(0, OutboundMessage::count());
        Queue::assertNothingPushed();
    }

    public function test_the_messages_screen_says_whatsapp_is_off_and_offers_only_the_cancellation(): void
    {
        $this->harmlessTemplate();
        $this->whatsappOff();

        $page = Livewire::actingAs($this->owner)->test(Messages::class);

        $page->assertSee(__('app.messages.whatsapp_off'));
        $this->assertSame(['day_cancelled'], $page->viewData('templates')->pluck('key')->all());
    }

    public function test_the_messages_screen_cancels_the_day_and_says_nothing_was_sent(): void
    {
        $this->whatsappOff();
        $booking = $this->bookingAt('09:00');

        Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'day_cancelled')
            ->call('confirm')
            ->call('send')
            ->assertSet('failed', false)
            ->assertSet('notice', __('app.messages.cancelled_without_messages', ['count' => 1]));

        $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, OutboundMessage::count());
    }

    /** Switched back on, everything works as before — nothing was left behind. */
    public function test_switching_back_on_restores_sending(): void
    {
        $this->whatsappOff();
        $this->clinic->update(['whatsapp_enabled' => true]);
        $this->bookingAt('09:00');
        Sanctum::actingAs($this->secretary);

        $this->postJson(route('api.v1.broadcasts.store'), ['template_key' => 'day_cancelled', 'date' => self::DAY])
            ->assertOk()
            ->assertJsonPath('data.sent_count', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function whatsappOff(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
    }

    /** A broadcast template that does not cancel anything — seeded inactive, so switched on here. */
    private function harmlessTemplate(): string
    {
        MessageTemplate::where('key', 'appointment_delayed')->update(['is_active' => true]);

        return 'appointment_delayed';
    }

    private function bookingAt(string $time): Booking
    {
        $patient = Patient::factory()->for($this->clinic)->create(['whatsapp_opt_in_at' => now()]);

        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' '.$time, 'Africa/Cairo'))
            ->create(['patient_id' => $patient->id]);
    }
}
