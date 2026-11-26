<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\BookingStatus;
use App\Livewire\App\Queue;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * A booking's status never moves ahead of its own day: a booking for tomorrow
 * is never marked arrived, in or done tonight. Behind it — a night the
 * secretary left unfinished — it may be corrected within the last
 * clinic.status.past_correction_days days, without inventing times and
 * without messaging the patient.
 */
class StatusOnTheVisitDayTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo']);
        Carbon::setTestNow(Carbon::parse('2026-09-03 12:00:00', 'Africa/Cairo'));
        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bookingOn(string $date, array $attributes = []): Booking
    {
        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse($date.' 17:00', 'Africa/Cairo'))
            ->create($attributes);
    }

    private function move(Booking $booking, BookingStatus $to)
    {
        return $this->postJson(route('api.v1.bookings.status', $booking), ['to' => $to->value]);
    }

    public function test_tomorrows_booking_cannot_be_marked_arrived(): void
    {
        $booking = $this->bookingOn('2026-09-04');

        $this->move($booking, BookingStatus::ARRIVED)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BOOKING_NOT_TODAY')
            ->assertJsonPath('error.details.visit_date', '2026-09-04')
            ->assertJsonPath('error.details.today', '2026-09-03');

        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }

    public function test_tomorrows_booking_cannot_be_marked_no_show(): void
    {
        $booking = $this->bookingOn('2026-09-04');

        $this->move($booking, BookingStatus::NO_SHOW)->assertStatus(409);

        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }

    /** Last night's patient left "with the doctor": finished the next morning. */
    public function test_yesterdays_booking_can_be_finished(): void
    {
        $booking = $this->bookingOn('2026-09-02', [
            'status' => BookingStatus::WITH_DOCTOR,
            'arrived_at' => '2026-09-02 17:05:00',
            'called_in_at' => '2026-09-02 17:20:00',
            'duration_minutes' => 10,
        ]);

        $this->move($booking, BookingStatus::DONE)->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::DONE, $fresh->status);
        // By the end-of-day close's rule: call-in plus the visit's length.
        $this->assertSame('2026-09-02 17:30:00', $fresh->completed_at->format('Y-m-d H:i:s'));
    }

    /** Arrived, in and done the next morning: no 12:00 "arrival" is invented. */
    public function test_a_past_correction_records_no_invented_times(): void
    {
        $booking = $this->bookingOn('2026-09-02');

        $this->move($booking, BookingStatus::ARRIVED)->assertOk();
        $this->move($booking->fresh(), BookingStatus::WITH_DOCTOR)->assertOk();
        $this->move($booking->fresh(), BookingStatus::DONE)->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::DONE, $fresh->status);
        $this->assertNull($fresh->arrived_at);
        $this->assertNull($fresh->called_in_at);
        // No call-in time: the booked end, not the moment of the correction.
        $this->assertSame($fresh->end_at->format('Y-m-d H:i:s'), $fresh->completed_at->format('Y-m-d H:i:s'));
    }

    public function test_a_past_visit_marked_done_sends_the_patient_nothing(): void
    {
        $this->clinic->update(['whatsapp_enabled' => true]);
        $this->visitCompletedTemplate();
        $booking = $this->bookingOn('2026-09-02', ['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => '2026-09-02 17:20:00']);

        $this->move($booking, BookingStatus::DONE)->assertOk();

        $this->assertSame(0, OutboundMessage::where('template_key', WhatsAppMessagingService::VISIT_COMPLETED_KEY)->count());
    }

    /** Today's visit still thanks the patient, as before. */
    public function test_todays_visit_marked_done_still_thanks_the_patient(): void
    {
        $this->clinic->update(['whatsapp_enabled' => true]);
        $this->visitCompletedTemplate();
        $booking = $this->bookingOn('2026-09-03', ['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => now()]);

        $this->move($booking, BookingStatus::DONE)->assertOk();

        $this->assertSame(1, OutboundMessage::where('template_key', WhatsAppMessagingService::VISIT_COMPLETED_KEY)->count());
        $this->assertSame('2026-09-03 12:00:00', $booking->fresh()->completed_at->format('Y-m-d H:i:s'));
    }

    public function test_seven_days_back_is_still_correctable(): void
    {
        $booking = $this->bookingOn('2026-08-27');

        $this->move($booking, BookingStatus::ARRIVED)->assertOk();
    }

    public function test_more_than_seven_days_back_is_refused(): void
    {
        $booking = $this->bookingOn('2026-08-26');

        foreach ([BookingStatus::ARRIVED, BookingStatus::NO_SHOW] as $to) {
            $this->move($booking, $to)
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'BOOKING_TOO_OLD')
                ->assertJsonPath('error.details.visit_date', '2026-08-26')
                ->assertJsonPath('error.details.days', 7);
        }

        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }

    public function test_the_window_follows_the_setting(): void
    {
        config(['clinic.status.past_correction_days' => 1]);

        $this->move($this->bookingOn('2026-09-02'), BookingStatus::ARRIVED)->assertOk();
        $this->move($this->bookingOn('2026-09-01'), BookingStatus::ARRIVED)->assertStatus(409)->assertJsonPath('error.code', 'BOOKING_TOO_OLD');
    }

    /** Correcting yesterday says nothing about whether the doctor started today. */
    public function test_a_past_correction_does_not_set_off_todays_turn_alert(): void
    {
        $yesterday = $this->bookingOn('2026-09-02', ['status' => BookingStatus::ARRIVED]);

        $this->move($yesterday, BookingStatus::WITH_DOCTOR)->assertOk();

        $this->assertSame(0, Booking::query()->forClinic($this->clinic->id)->whereDate('visit_date', '2026-09-03')->whereNotNull('called_in_at')->count());
        $this->assertNull($yesterday->fresh()->called_in_at);
    }

    /**
     * 25 November 2026: at 03:57 a 00:00 booking from Tuesday night, left
     * "with the doctor", could not be finished — Tuesday had stopped being
     * the clinic's day at about 02:10, and the close was not until 06:00.
     */
    public function test_the_25_november_case_can_now_be_finished(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'Africa/Cairo'));
        $booking = $this->bookingOn('2026-11-24', ['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => '2026-11-25 01:29:31', 'duration_minutes' => 10]);
        $booking->update(['start_at' => Carbon::parse('2026-11-25 00:00', 'Africa/Cairo'), 'end_at' => Carbon::parse('2026-11-25 00:10', 'Africa/Cairo')]);

        Carbon::setTestNow(Carbon::parse('2026-11-25 03:57:10', 'Africa/Cairo'));
        $this->assertSame('2026-11-25', $this->clinic->fresh()->currentDay()->toDateString());

        $this->move($booking->fresh(), BookingStatus::DONE)->assertOk();

        $this->assertSame('2026-11-25 01:39:31', $booking->fresh()->completed_at->format('Y-m-d H:i:s'));
    }

    private function visitCompletedTemplate(): void
    {
        MessageTemplate::updateOrCreate(
            ['key' => WhatsAppMessagingService::VISIT_COMPLETED_KEY],
            [
                'category' => 'utility',
                'is_broadcast' => false,
                'body_ar' => 'شكراً لزيارتك {{1}}.',
                'provider_template_name' => WhatsAppMessagingService::VISIT_COMPLETED_KEY,
                'is_active' => true,
            ],
        );
    }

    public function test_a_no_show_can_be_recorded_after_the_day(): void
    {
        $booking = $this->bookingOn('2026-09-02');

        $this->move($booking, BookingStatus::NO_SHOW)->assertOk();

        $this->assertSame(BookingStatus::NO_SHOW, $booking->fresh()->status);
    }

    public function test_todays_booking_moves_as_before(): void
    {
        $booking = $this->bookingOn('2026-09-03');

        $this->move($booking, BookingStatus::ARRIVED)->assertOk();
        $this->move($booking->fresh(), BookingStatus::WITH_DOCTOR)->assertOk();
        $this->move($booking->fresh(), BookingStatus::DONE)->assertOk();

        $this->assertSame(BookingStatus::DONE, $booking->fresh()->status);
    }

    public function test_after_midnight_last_nights_patients_can_still_be_finished(): void
    {
        // Still waiting from last night: the clinic day has not rolled over.
        $booking = $this->bookingOn('2026-09-03', ['status' => BookingStatus::WITH_DOCTOR, 'arrived_at' => now(), 'called_in_at' => now()]);
        $booking->update(['start_at' => Carbon::parse('2026-09-03 23:50', 'Africa/Cairo'), 'end_at' => Carbon::parse('2026-09-04 00:00', 'Africa/Cairo')]);
        Carbon::setTestNow(Carbon::parse('2026-09-04 00:20:00', 'Africa/Cairo'));

        $this->move($booking->fresh(), BookingStatus::DONE)->assertOk();
    }

    public function test_the_clinic_screen_refuses_it_too(): void
    {
        $booking = $this->bookingOn('2026-09-04');

        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->call('arrive', $booking->id);

        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }
}
