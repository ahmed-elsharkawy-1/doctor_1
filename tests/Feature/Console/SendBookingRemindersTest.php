<?php

namespace Tests\Feature\Console;

use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class SendBookingRemindersTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bookings are taken in the morning, well before the hour before.
        $this->at('08:00');

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic->update([
            'address' => '12 شارع مصدق، الدقي',
            'patient_arrival_lead_minutes' => 15,
        ]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse("2026-09-03 {$time}:00", 'Africa/Cairo'));
    }

    private function bookingAt(string $time): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse("2026-09-03 {$time}", 'Africa/Cairo'))
            ->create();
    }

    /** @return Collection<int, OutboundMessage> */
    private function reminders()
    {
        return OutboundMessage::where('template_key', WhatsAppMessagingService::REMINDER_KEY)->get();
    }

    public function test_the_reminder_goes_out_an_hour_before(): void
    {
        $booking = $this->bookingAt('10:00');

        $this->at('08:59');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(0, $this->reminders());

        $this->at('09:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $reminder = $this->reminders()->sole();

        $this->assertSame($booking->id, $reminder->booking_id);
        $this->assertSame('queued', $reminder->status);
        // The approved template's six values, in its order.
        $this->assertCount(6, $reminder->variables);
        $this->assertSame('10:00 '.__('schedule.am'), $reminder->variables[2]);
        $this->assertSame('15 دقيقة', $reminder->variables[5]);
        $this->assertSame('booking/'.$booking->tracking_token, $reminder->button_suffix);
    }

    public function test_a_booking_is_reminded_once(): void
    {
        $this->bookingAt('10:00');

        foreach (['09:00', '09:01', '09:30'] as $time) {
            $this->at($time);
            $this->artisan('clinic:send-reminders')->assertSuccessful();
        }

        $this->assertCount(1, $this->reminders());
    }

    /** A run that was missed is caught up, but never once the time has come. */
    public function test_a_missed_reminder_is_caught_up_only_while_the_appointment_is_ahead(): void
    {
        $this->bookingAt('10:00');
        $this->bookingAt('09:40');

        $this->at('09:45');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(1, $this->reminders());
    }

    /** Its confirmation went out a moment ago and says the same thing. */
    public function test_a_booking_made_inside_the_hour_is_not_reminded(): void
    {
        $this->at('09:30');
        $this->bookingAt('10:00');

        $this->at('09:31');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_only_bookings_still_waiting_are_reminded(): void
    {
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse('2026-09-03 10:00', 'Africa/Cairo'))->arrived()->create();
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse('2026-09-03 10:00', 'Africa/Cairo'))->cancelled()->create();

        $this->at('09:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_nothing_goes_to_a_patient_who_has_not_opted_in(): void
    {
        $this->bookingAt('10:00')->patient->update(['whatsapp_opt_in_at' => null]);

        $this->at('09:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_nothing_goes_out_for_a_clinic_with_whatsapp_off(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        $this->bookingAt('10:00');

        $this->at('09:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }
}
