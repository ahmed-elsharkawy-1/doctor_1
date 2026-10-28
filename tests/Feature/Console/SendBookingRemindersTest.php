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
 * The pre-visit reminder, timed by the clinic's slot position: the reminder
 * goes out `reminderPosition × the booking's own duration` before the visit.
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

        $this->at('07:00');

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic->update([
            'address' => '12 شارع مصدق، الدقي',
            'patient_arrival_lead_minutes' => 15,
            // One slot ahead: the simplest baseline to read the tests against.
            'reminder_position' => 1,
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

    private function bookingAt(string $time, int $duration = 20, string $day = self::DAY): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse("{$day} {$time}", 'Africa/Cairo'), $duration)
            ->create();
    }

    /** @return Collection<int, OutboundMessage> */
    private function reminders()
    {
        return OutboundMessage::where('template_key', WhatsAppMessagingService::REMINDER_KEY)->get();
    }

    /** @return list<int> */
    private function remindedBookingIds(): array
    {
        return $this->reminders()->pluck('booking_id')->sort()->values()->all();
    }

    public function test_the_first_patient_is_reminded_one_slot_before_the_visit(): void
    {
        $booking = $this->bookingAt('15:00');

        $this->at('14:39');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(0, $this->reminders());

        $this->at('14:40');
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

    public function test_the_clinic_position_multiplies_the_slot_length(): void
    {
        $this->clinic->update(['reminder_position' => 6]);

        $this->bookingAt('15:00', 30);

        // Six 30-minute slots ahead: three hours.
        $this->at('11:59');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(0, $this->reminders());

        $this->at('12:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(1, $this->reminders());
    }

    public function test_every_patient_is_timed_by_her_own_visit(): void
    {
        $this->clinic->update(['reminder_position' => 2]);

        $first = $this->bookingAt('13:00', 30);
        $second = $this->bookingAt('14:00', 30);
        $third = $this->bookingAt('15:00', 30);

        $this->at('11:59');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(0, $this->reminders());

        // Each is reminded two of its own slots ahead of its own start.
        $this->at('12:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertSame([$first->id], $this->remindedBookingIds());

        $this->at('13:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertSame([$first->id, $second->id], $this->remindedBookingIds());

        $this->at('14:00');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertSame([$first->id, $second->id, $third->id], $this->remindedBookingIds());
    }

    public function test_each_booking_uses_its_own_slot_length(): void
    {
        $this->clinic->update(['reminder_position' => 2]);

        $long = $this->bookingAt('09:00', 45);
        $short = $this->bookingAt('10:00', 15);

        // Two 45-minute slots: 90 minutes ahead.
        $this->at('07:29');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(0, $this->reminders());

        $this->at('07:30');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertSame([$long->id], $this->remindedBookingIds());

        // Two 15-minute slots: only half an hour ahead.
        $this->at('09:29');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertSame([$long->id], $this->remindedBookingIds());

        $this->at('09:30');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertSame([$long->id, $short->id], $this->remindedBookingIds());
    }

    /**
     * The reminder may fall before midnight for a visit just after it. The
     * day that matters is the visit's, not the clock's.
     */
    public function test_a_lead_across_midnight_still_uses_the_visits_own_day(): void
    {
        $this->clinic->update(['reminder_position' => 6]);

        $booking = $this->bookingAt('00:30', 30, '2026-09-04');

        $this->at('21:29');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(0, $this->reminders());

        // 21:30 + 6 slots × 30 minutes = 00:30 the next day.
        $this->at('21:30');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertSame([$booking->id], $this->remindedBookingIds());
    }

    public function test_no_reminder_goes_out_on_the_visits_holiday(): void
    {
        $this->clinic->update(['reminder_position' => 6]);

        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => '2026-09-04',
        ]);
        $this->bookingAt('00:30', 30, '2026-09-04');

        $this->at('21:30');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_a_reminder_is_sent_once(): void
    {
        $this->bookingAt('15:00');

        foreach (['14:35', '14:40', '14:45', '15:00'] as $time) {
            $this->at($time);
            $this->artisan('clinic:send-reminders')->assertSuccessful();
        }

        $this->assertCount(1, $this->reminders());
    }

    /** Its confirmation went out a moment ago and says everything it would. */
    public function test_a_booking_made_after_its_remind_time_gets_no_reminder(): void
    {
        $this->at('14:45');
        $this->bookingAt('15:00');

        $this->at('14:50');
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

    public function test_nothing_goes_to_a_patient_who_has_not_opted_in(): void
    {
        $this->bookingAt('15:00')->patient->update(['whatsapp_opt_in_at' => null]);

        $this->at('14:40');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }

    public function test_nothing_goes_out_for_a_clinic_with_whatsapp_off(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        $this->bookingAt('15:00');

        $this->at('14:40');
        $this->artisan('clinic:send-reminders')->assertSuccessful();

        $this->assertCount(0, $this->reminders());
    }
}
