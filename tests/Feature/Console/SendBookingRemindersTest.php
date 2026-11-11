<?php

namespace Tests\Feature\Console;

use App\Enums\DayOfWeek;
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
 * The turn alert: once per booking, when no more than the clinic's position
 * are still ahead and either the doctor has started or the booked time is
 * 15 minutes away.
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
            // One patient ahead: the simplest baseline to read the tests against.
            'reminder_position' => 1,
            // Off on every clinic until a super admin switches it on.
            'pre_visit_reminder_enabled' => true,
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

    private function doctorHasStarted(string $time = '09:00'): Booking
    {
        // Marked in at the visit's own time, not the test's clock.
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY." {$time}", 'Africa/Cairo'), 20)
            ->withDoctor()
            ->create();
    }

    /** The visit day's one period, from this time until late. */
    private function openFrom(string $time): void
    {
        $day = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $day->update(['is_open' => true]);
        $day->periods()->delete();
        $day->periods()->create(['start_time' => $time, 'end_time' => '23:00']);
    }

    private function sweep(): void
    {
        $this->artisan('clinic:send-reminders')->assertSuccessful();
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

    public function test_the_first_patient_waits_for_the_booked_time_when_the_doctor_has_not_started(): void
    {
        $booking = $this->bookingAt('15:00');

        // Nobody is ahead from the start of the day, and that alone is not enough.
        $this->at('14:44');
        $this->sweep();
        $this->assertCount(0, $this->reminders());

        $this->at('14:45');
        $this->sweep();

        $upcoming = $this->reminders()->sole();

        $this->assertSame($booking->id, $upcoming->booking_id);
        $this->assertSame('queued', $upcoming->status);
        $this->assertSame('template', $upcoming->type);

        // The turn alert carries the patient's name and nothing else.
        $this->assertSame([$booking->patient->name], $upcoming->variables);

        // The tracking link rides on its button, not in the body.
        $this->assertSame('booking/'.$booking->tracking_token, $upcoming->button_suffix);
        $this->assertStringNotContainsString('booking/'.$booking->tracking_token, $upcoming->rendered_body);
        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    public function test_the_lead_before_the_booked_time_is_configurable(): void
    {
        config(['clinic.turn_alert.first_patients_lead_minutes' => 30]);

        $this->bookingAt('15:00');

        $this->at('14:29');
        $this->sweep();
        $this->assertCount(0, $this->reminders());

        $this->at('14:30');
        $this->sweep();
        $this->assertCount(1, $this->reminders());
    }

    public function test_once_the_doctor_has_started_a_close_patient_does_not_wait_for_the_booked_time(): void
    {
        $this->at('14:00');
        $this->doctorHasStarted('14:00');
        $booking = $this->bookingAt('15:00');

        $this->sweep();

        $this->assertSame([$booking->id], $this->remindedBookingIds());
    }

    /**
     * Marked in long before the day opens — in the small hours, say, while
     * closing the night before — it says nothing about the doctor today.
     */
    public function test_a_visit_marked_in_long_before_opening_is_not_the_doctor_starting(): void
    {
        $this->openFrom('14:00');
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 14:00', 'Africa/Cairo'), 20)
            ->create(['status' => 'done', 'arrived_at' => Carbon::parse(self::DAY.' 00:53', 'Africa/Cairo'), 'called_in_at' => Carbon::parse(self::DAY.' 00:53', 'Africa/Cairo'), 'completed_at' => Carbon::parse(self::DAY.' 00:53', 'Africa/Cairo')]);
        $this->bookingAt('15:00');

        $this->at('00:56');
        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    public function test_a_visit_marked_in_shortly_before_opening_counts(): void
    {
        $this->openFrom('14:00');
        $this->at('13:10');
        $this->doctorHasStarted('13:10');
        $booking = $this->bookingAt('15:00');

        $this->sweep();

        $this->assertSame([$booking->id], $this->remindedBookingIds());
    }

    public function test_a_doctor_who_has_finished_a_patient_has_still_started(): void
    {
        $this->at('14:00');
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 13:00', 'Africa/Cairo'), 20)
            ->done()
            ->create();
        $booking = $this->bookingAt('15:00');

        $this->sweep();

        $this->assertSame([$booking->id], $this->remindedBookingIds());
    }

    public function test_a_start_on_another_day_does_not_count(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-02 13:00', 'Africa/Cairo'), 20)
            ->done()
            ->create();
        $this->bookingAt('15:00');

        $this->at('14:00');
        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    public function test_the_alert_follows_the_line_not_the_clock(): void
    {
        $this->clinic->update(['reminder_position' => 2]);

        $inside = $this->doctorHasStarted();
        $first = $this->bookingAt('13:00', 30);
        $second = $this->bookingAt('14:00', 30);
        $third = $this->bookingAt('15:00', 30);
        $fourth = $this->bookingAt('16:00', 30);

        $this->at('12:00');
        $this->sweep();
        $this->assertSame([$first->id, $second->id, $third->id], $this->remindedBookingIds());

        // The first goes in: one fewer ahead of the fourth, so it is now due.
        $inside->update(['status' => 'done', 'completed_at' => now()]);
        $first->update(['status' => 'with_doctor', 'arrived_at' => now(), 'called_in_at' => now()]);

        $this->sweep();
        $this->assertSame([$first->id, $second->id, $third->id, $fourth->id], $this->remindedBookingIds());
    }

    public function test_a_long_late_day_does_not_reach_a_patient_with_too_many_ahead(): void
    {
        $this->bookingAt('13:00');
        $this->bookingAt('13:20');
        $far = $this->bookingAt('13:40');

        // Past every booked time, but two are still ahead of the last.
        $this->at('15:00');
        $this->sweep();

        $this->assertNotContains($far->id, $this->remindedBookingIds());
    }

    public function test_a_patient_whose_booked_time_has_passed_is_still_alerted(): void
    {
        $booking = $this->bookingAt('13:00');

        $this->at('14:30');
        $this->sweep();

        $this->assertSame([$booking->id], $this->remindedBookingIds());
    }

    public function test_those_ahead_count_whether_or_not_they_have_arrived(): void
    {
        $arrived = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 13:00', 'Africa/Cairo'), 20)
            ->arrived()
            ->create();
        $onTheWay = $this->bookingAt('13:20');
        $booking = $this->bookingAt('13:40');

        $this->at('14:00');
        $this->sweep();

        $this->assertNotContains($booking->id, $this->remindedBookingIds());

        // Someone ahead cancels: one left ahead, so now it is due.
        $onTheWay->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->sweep();

        $this->assertContains($booking->id, $this->remindedBookingIds());
        $this->assertNotContains($arrived->id, $this->remindedBookingIds());
    }

    public function test_an_emergency_in_the_clinic_counts_as_ahead_and_gets_no_alert(): void
    {
        $emergency = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 13:00', 'Africa/Cairo'), 20)
            ->arrived()
            ->create(['booking_kind' => 'emergency']);
        $onTheWay = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 13:10', 'Africa/Cairo'), 20)
            ->create(['booking_kind' => 'emergency']);
        $first = $this->bookingAt('13:20');
        $second = $this->bookingAt('13:40');

        $this->at('14:00');
        $this->sweep();

        // The arrived emergency is ahead of both; the one on the way is ahead
        // of nobody and, like every emergency, is not messaged.
        $this->assertSame([$first->id], $this->remindedBookingIds());
        $this->assertNotContains($emergency->id, $this->remindedBookingIds());
        $this->assertNotContains($onTheWay->id, $this->remindedBookingIds());
        $this->assertNotContains($second->id, $this->remindedBookingIds());
    }

    public function test_a_patient_who_has_arrived_gets_no_alert(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 13:00', 'Africa/Cairo'), 20)
            ->arrived()
            ->create();

        $this->at('13:00');
        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    public function test_a_booking_made_when_the_turn_is_already_close_is_alerted(): void
    {
        $this->at('14:45');
        $this->doctorHasStarted('14:30');
        $booking = $this->bookingAt('15:00');

        $this->sweep();

        $this->assertSame([$booking->id], $this->remindedBookingIds());
    }

    public function test_tomorrows_booking_is_left_alone(): void
    {
        $this->doctorHasStarted();
        $this->bookingAt('15:00', 20, '2026-09-04');

        $this->at('15:00');
        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    /**
     * After midnight the visit still belongs to the day it was booked on.
     */
    public function test_a_visit_after_midnight_stays_on_its_own_day(): void
    {
        $booking = $this->bookingAt('23:50');
        $booking->update([
            'start_at' => Carbon::parse('2026-09-04 00:30', 'Africa/Cairo'),
            'end_at' => Carbon::parse('2026-09-04 00:50', 'Africa/Cairo'),
        ]);

        $this->at('00:14', '2026-09-04');
        $this->sweep();
        $this->assertCount(0, $this->reminders());

        $this->at('00:15', '2026-09-04');
        $this->sweep();
        $this->assertSame([$booking->id], $this->remindedBookingIds());
    }

    public function test_no_alert_goes_out_on_a_holiday(): void
    {
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => self::DAY,
        ]);
        $this->bookingAt('15:00');

        $this->at('15:00');
        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    public function test_an_alert_is_sent_once(): void
    {
        $this->bookingAt('15:00');

        foreach (['14:35', '15:00', '15:05', '15:30'] as $time) {
            $this->at($time);
            $this->sweep();
        }

        $this->assertCount(1, $this->reminders());
    }

    public function test_nothing_goes_to_a_patient_who_has_not_opted_in(): void
    {
        $this->bookingAt('15:00')->patient->update(['whatsapp_opt_in_at' => null]);

        $this->at('15:00');
        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    public function test_nothing_goes_out_for_a_clinic_with_whatsapp_off(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        $this->bookingAt('15:00');

        $this->at('15:00');
        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    public function test_nothing_goes_out_while_the_clinics_switch_is_off(): void
    {
        $this->clinic->update(['pre_visit_reminder_enabled' => false]);
        $this->bookingAt('15:00');

        $this->at('15:00');
        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    /**
     * The two reminders are switched separately: the morning one being off
     * must not take this one with it.
     */
    public function test_it_does_not_depend_on_the_morning_switch(): void
    {
        $this->clinic->update(['daily_reminder_enabled' => false]);
        $this->bookingAt('15:00');

        $this->at('15:00');
        $this->sweep();

        $this->assertCount(1, $this->reminders());
    }
}
