<?php

namespace Tests\Feature\Console;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Booking;
use App\Models\ClinicHoliday;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The app's original reminder: an hour before each visit.
 *
 * `clinic:send-reminders` is the command develop scheduled; the daily one at
 * the clinic's own hour is `clinic:send-daily-reminders` and has its own
 * suite. WhatsApp costs money, so this class is as much about who it leaves
 * alone as who it reaches.
 */
class SendBookingRemindersTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    protected function setUp(): void
    {
        parent::setUp();

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

    private function at(string $time, string $day = self::DAY): void
    {
        Carbon::setTestNow(Carbon::parse("{$day} {$time}:00", 'Africa/Cairo'));
    }

    private function bookingAt(string $time, string $day = self::DAY): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse("{$day} {$time}", 'Africa/Cairo'))
            ->create();
    }

    /** @return Collection<int, OutboundMessage> */
    private function reminders()
    {
        return OutboundMessage::where('template_key', WhatsAppMessagingService::REMINDER_KEY)->get();
    }

    public function test_the_reminder_goes_out_an_hour_before_the_visit(): void
    {
        $booking = $this->bookingAt('15:00');

        $this->at('13:59');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(0, $this->reminders());

        $this->at('14:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $upcoming = $this->reminders()->sole();

        $this->assertSame($booking->id, $upcoming->booking_id);
        $this->assertSame('queued', $upcoming->status);
        $this->assertSame('template', $upcoming->type);

        // The approved template's five values, in its order.
        $this->assertCount(5, $upcoming->variables);
        $this->assertSame('03:00 '.__('schedule.pm'), $upcoming->variables[2]);

        // Develop's own reminder carried the tracking link on its button.
        $this->assertSame('booking/'.$booking->tracking_token, $upcoming->button_suffix);
        $this->assertStringNotContainsString('booking/'.$booking->tracking_token, $upcoming->rendered_body);
        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    public function test_a_reminder_is_sent_once(): void
    {
        $this->bookingAt('15:00');

        foreach (['13:30', '14:00', '14:45', '15:30'] as $time) {
            $this->at($time);
            $this->artisan('clinic:send-reminders')->assertSuccessful();
        }

        $this->assertCount(1, $this->reminders());
    }

    /** Its confirmation went out a moment ago and says everything it would. */
    public function test_a_booking_made_inside_the_final_hour_gets_no_reminder(): void
    {
        $this->at('14:10');
        $this->bookingAt('15:00');

        $this->at('14:15');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_a_visit_that_already_started_gets_no_reminder(): void
    {
        $this->bookingAt('14:50');

        $this->at('14:55');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_no_reminder_goes_out_on_a_holiday(): void
    {
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => self::DAY,
        ]);
        $this->bookingAt('15:00');

        $this->at('14:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_nothing_goes_to_a_patient_who_has_not_opted_in(): void
    {
        $this->bookingAt('15:00')->patient->update(['whatsapp_opt_in_at' => null]);

        $this->at('14:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_nothing_goes_out_for_a_clinic_with_whatsapp_off(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        $this->bookingAt('15:00');

        $this->at('14:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }
}
