<?php

namespace Tests\Feature\Console;

use App\Enums\BookingKind;
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
 * One reminder per booking, on the visit day, at the clinic's own time.
 *
 * `clinic:send-daily-reminders` is the new command; `clinic:send-reminders`
 * remains the hourly one an hour before each visit, with its own suite.
 * WhatsApp costs money, so this class is as much about who it leaves alone as
 * who it reaches.
 */
class SendDailyRemindersTest extends TestCase
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
            'reminder_time' => '11:00',
            'reminder_position' => 1,
            // Both reminders start off on every clinic; these tests are about
            // what each does once a super admin switches it on.
            'daily_reminder_enabled' => true,
            'pre_visit_reminder_enabled' => true,
            // "Made before the reminder time" is the baseline these tests
            // read against; the minimum-hours rule has its own tests below.
            'daily_reminder_min_hours' => 0,
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
    private function dailyReminders()
    {
        return OutboundMessage::where('template_key', WhatsAppMessagingService::DAILY_REMINDER_KEY)->get();
    }

    public function test_the_reminder_goes_out_at_the_clinic_reminder_time(): void
    {
        $booking = $this->bookingAt('15:00');

        $this->at('10:59');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();
        $this->assertCount(0, $this->dailyReminders());

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $reminder = $this->dailyReminders()->sole();

        $this->assertSame($booking->id, $reminder->booking_id);
        $this->assertSame('queued', $reminder->status);
        $this->assertSame('template', $reminder->type);

        // The approved template's five values, in its order.
        $this->assertCount(5, $reminder->variables);
        $this->assertSame('03:00 '.__('schedule.pm'), $reminder->variables[2]);
        // No link in the first message — the buttons are the only action.
        $this->assertNull($reminder->button_suffix);
        $this->assertStringNotContainsString('booking/'.$booking->tracking_token, $reminder->rendered_body);
        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    public function test_a_booking_is_reminded_once(): void
    {
        $this->bookingAt('15:00');

        foreach (['11:00', '11:01', '11:30', '13:00'] as $time) {
            $this->at($time);
            $this->artisan('clinic:send-daily-reminders')->assertSuccessful();
        }

        $this->assertCount(1, $this->dailyReminders());
    }

    public function test_a_booking_made_after_the_reminder_time_is_skipped(): void
    {
        $this->at('11:03');

        $this->bookingAt('15:00');

        $this->at('11:05');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    public function test_an_emergency_booking_is_never_reminded(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 15:00', 'Africa/Cairo'))
            ->create(['booking_kind' => BookingKind::EMERGENCY, 'start_at' => null, 'end_at' => null]);

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    /** A morning appointment earlier than the reminder time would get it too late. */
    public function test_an_appointment_before_the_reminder_time_is_skipped(): void
    {
        $this->bookingAt('09:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    /** Cutoff 60 at 11:00: an 11:45 visit could not cancel from the reminder, so it gets none. */
    public function test_a_visit_already_inside_the_cancel_cutoff_is_skipped(): void
    {
        $this->clinic->update(['whatsapp_cancel_cutoff_minutes' => 60]);
        $this->bookingAt('11:45');
        $later = $this->bookingAt('12:30');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertSame([$later->id], $this->dailyReminders()->pluck('booking_id')->all());
    }

    public function test_with_no_cancel_cutoff_a_visit_soon_after_the_reminder_is_reminded(): void
    {
        $this->clinic->update(['whatsapp_cancel_cutoff_minutes' => 0]);
        $this->bookingAt('11:15');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(1, $this->dailyReminders());
    }

    public function test_only_bookings_still_waiting_are_reminded(): void
    {
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse(self::DAY.' 15:00', 'Africa/Cairo'))->arrived()->create();
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse(self::DAY.' 16:00', 'Africa/Cairo'))->cancelled()->create();

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    public function test_another_days_booking_is_never_reminded(): void
    {
        $this->bookingAt('15:00', '2026-09-04');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    public function test_nothing_goes_to_a_patient_who_has_not_opted_in(): void
    {
        $this->bookingAt('15:00')->patient->update(['whatsapp_opt_in_at' => null]);

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    /*
    |--------------------------------------------------------------------------
    | Booked at least N hours before the reminder time
    |--------------------------------------------------------------------------
    |
    | A booking made late the night before has just had its confirmation; the
    | morning reminder would be a second message about the same visit.
    */

    private function bookingMadeAt(string $madeAt, string $visitAt): Booking
    {
        [$day, $time] = explode(' ', $madeAt);
        $this->at($time, $day);

        return $this->bookingAt($visitAt);
    }

    public function test_a_booking_made_twelve_hours_before_the_reminder_time_is_reminded(): void
    {
        $this->clinic->update(['daily_reminder_min_hours' => 12]);

        $this->bookingMadeAt('2026-09-02 23:00', '15:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(1, $this->dailyReminders());
    }

    public function test_a_booking_made_less_than_twelve_hours_before_is_skipped(): void
    {
        $this->clinic->update(['daily_reminder_min_hours' => 12]);

        $this->bookingMadeAt('2026-09-02 23:01', '15:00');

        foreach (['11:00', '12:00', '14:00'] as $time) {
            $this->at($time);
            $this->artisan('clinic:send-daily-reminders')->assertSuccessful();
        }

        $this->assertCount(0, $this->dailyReminders());
    }

    public function test_the_hours_follow_the_clinics_own_setting(): void
    {
        $this->clinic->update(['daily_reminder_min_hours' => 3]);

        $early = $this->bookingMadeAt(self::DAY.' 08:00', '15:00');
        $late = $this->bookingMadeAt(self::DAY.' 08:01', '16:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertSame([$early->id], $this->dailyReminders()->pluck('booking_id')->all());
        $this->assertSame('booked', $late->fresh()->status->value);
    }

    /*
    |--------------------------------------------------------------------------
    | The clinic's switch
    |--------------------------------------------------------------------------
    */

    public function test_nothing_goes_out_while_the_clinics_switch_is_off(): void
    {
        $this->clinic->update(['daily_reminder_enabled' => false]);
        $this->bookingAt('15:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    /**
     * The two reminders are switched separately: the pre-visit one being off
     * must not take the morning one with it.
     */
    public function test_it_does_not_depend_on_the_pre_visit_switch(): void
    {
        $this->clinic->update(['pre_visit_reminder_enabled' => false]);
        $this->bookingAt('15:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(1, $this->dailyReminders());
    }

    public function test_nothing_goes_out_for_a_clinic_with_whatsapp_off(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        $this->bookingAt('15:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    public function test_no_reminders_go_out_on_a_holiday(): void
    {
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => self::DAY,
        ]);
        $this->bookingAt('15:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(0, $this->dailyReminders());
    }

    /** A booking moved to today before the reminder time is reminded today. */
    public function test_a_booking_moved_to_today_follows_its_new_date(): void
    {
        $this->at('08:00', '2026-09-02');
        $this->bookingAt('15:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(1, $this->dailyReminders());
    }

    public function test_changing_the_reminder_time_changes_the_next_days_send(): void
    {
        $this->bookingAt('15:00');

        $this->clinic->update(['reminder_time' => '13:30']);

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();
        $this->assertCount(0, $this->dailyReminders());

        $this->at('13:30');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();
        $this->assertCount(1, $this->dailyReminders());
    }

    /**
     * The two reminders are independent commands. The day's goes out from its
     * scheduler, the pre-visit one from its own — and the booking gets each
     * exactly once, under its own key.
     */
    public function test_running_both_commands_gives_a_booking_one_of_each(): void
    {
        $booking = $this->bookingAt('15:00');

        $this->at('11:00');
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->assertCount(1, $this->dailyReminders());
        $this->assertCount(0, OutboundMessage::where(
            'template_key',
            WhatsAppMessagingService::REMINDER_KEY,
        )->get());

        // Position 1 of a 20-minute visit: one slot before it.
        $this->at('14:40');
        $this->artisan('clinic:send-reminders')->assertSuccessful();
        $this->artisan('clinic:send-daily-reminders')->assertSuccessful();

        $this->assertCount(1, $this->dailyReminders());
        $this->assertCount(1, OutboundMessage::where(
            'template_key',
            WhatsAppMessagingService::REMINDER_KEY,
        )->get());

        // The booking carries both, one relation each; the card reads the
        // newest.
        $booking->load('reminders', 'reminder', 'dailyReminder');
        $this->assertCount(2, $booking->reminders);
        $this->assertSame(
            [WhatsAppMessagingService::REMINDER_KEY, WhatsAppMessagingService::DAILY_REMINDER_KEY],
            $booking->reminders->pluck('template_key')->all(),
        );
        $this->assertSame(WhatsAppMessagingService::REMINDER_KEY, $booking->reminder->template_key);
        $this->assertSame(WhatsAppMessagingService::DAILY_REMINDER_KEY, $booking->dailyReminder->template_key);
    }
}
