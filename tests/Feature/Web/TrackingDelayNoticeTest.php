<?php

namespace Tests\Feature\Web;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Support\DelayLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The delay notice at the top of the tracking page (TrackingDelayService).
 *
 * The estimate is now + the minutes of everyone ahead, so each test sets the
 * clock and the bookings ahead to make the lateness exactly what it needs:
 * shown delay = (estimate − appointment) − reduction (30), rounded to 5,
 * shown only when more than the threshold (15).
 */
class TrackingDelayNoticeTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo']);
        config(['clinic.tracking.show_estimate' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function now(string $time): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 '.$time, 'Africa/Cairo'));
    }

    private function booking(string $time, int $duration = 20, array $attributes = []): Booking
    {
        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 '.$time), $duration)
            ->create($attributes);
    }

    /**
     * At 09:00, the patient booked 09:00 with one visit of $aheadMinutes ahead:
     * the estimate is 09:00 + $aheadMinutes, so the gap is exactly that.
     */
    private function lateBy(int $aheadMinutes): Booking
    {
        $this->now('09:00');
        $this->booking('08:00', $aheadMinutes);

        return $this->booking('09:00');
    }

    public function test_a_long_delay_shows_the_notice_with_the_reduced_delay_and_times(): void
    {
        // Three visits of 20 ahead at 10:00: estimate 11:00, an hour after 10:00.
        $this->now('10:00');
        $this->booking('08:00');
        $this->booking('08:20');
        $this->booking('08:40');
        $mine = $this->booking('10:00');

        // Gap 60 − 30 = 30: expected 10:30.
        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.delay_title'))
            ->assertSee(DelayLabel::for(30))
            ->assertSeeInOrder([
                __('booking.tracking.delay_previous'), '10:00 '.__('booking.tracking.am'),
                __('booking.tracking.delay_expected'), '10:30 '.__('booking.tracking.am'),
            ])
            ->assertSee(__('booking.tracking.delay_note'));
    }

    public function test_while_the_notice_shows_the_two_lower_boxes_are_hidden(): void
    {
        $mine = $this->lateBy(60);

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.delay_title'))
            ->assertDontSee(__('booking.tracking.expected_at'))
            ->assertDontSee(__('booking.tracking.your_status'));
    }

    public function test_the_threshold_is_exclusive(): void
    {
        // 45 − 30 = 15: not more than 15, so no delay — the arrival notice
        // instead, and the boxes stay hidden.
        $this->get($this->lateBy(45)->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.delay_title'))
            ->assertSee(__('booking.tracking.arrive_title'))
            ->assertDontSee(__('booking.tracking.expected_at'))
            ->assertDontSee(__('booking.tracking.your_status'));
    }

    /*
    |--------------------------------------------------------------------------
    | No delay: the arrival notice
    |--------------------------------------------------------------------------
    |
    | With the estimate on, a patient still to come always gets the yellow
    | notice: the delay when there is one worth announcing, otherwise "be here
    | before your appointment" with the time they booked.
    */

    public function test_on_time_the_notice_asks_the_patient_to_come_before_the_booked_time(): void
    {
        $this->now('08:00');
        $this->booking('08:00');
        $mine = $this->booking('08:30');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSeeInOrder([__('booking.tracking.arrive_title'), '8:30 '.__('booking.tracking.am')])
            ->assertSee(__('booking.tracking.delay_note'))
            ->assertDontSee(__('booking.tracking.delay_title'))
            ->assertDontSee(__('booking.tracking.expected_at'))
            ->assertDontSee(__('booking.tracking.your_status'));
    }

    /** A slip too small to announce is not announced: the booked time stands. */
    public function test_a_small_delay_still_shows_the_booked_time(): void
    {
        // 40 − 30 = 10, under the threshold; the estimate is 09:40.
        $this->get($this->lateBy(40)->trackingUrl())
            ->assertOk()
            ->assertSeeInOrder([__('booking.tracking.arrive_title'), '9:00 '.__('booking.tracking.am')])
            ->assertDontSee('9:40 '.__('booking.tracking.am'));
    }

    /**
     * Past the booked time and still not here: "come before 8:30" at 8:50
     * would ask for the impossible, so the notice says come now instead.
     */
    public function test_past_the_booked_time_the_notice_asks_the_patient_to_come_now(): void
    {
        $this->now('08:50');
        $mine = $this->booking('08:30');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSeeInOrder([__('booking.tracking.arrive_late_title'), '8:30 '.__('booking.tracking.am')])
            ->assertSee(__('booking.tracking.arrive_late_note'))
            ->assertDontSee(__('booking.tracking.arrive_title'))
            ->assertDontSee(__('booking.tracking.delay_note'))
            ->assertDontSee(__('booking.tracking.expected_at'));
    }

    /** A short visit ahead keeps the slip under the threshold: still late. */
    public function test_past_the_booked_time_with_someone_ahead_but_no_delay_says_come_now(): void
    {
        // At 08:50, booked 08:30, 10 minutes ahead: gap 30 − 30 = 0.
        $this->now('08:50');
        $this->booking('08:00', 10);
        $mine = $this->booking('08:30');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.delay_title'))
            ->assertSee(__('booking.tracking.arrive_late_title'))
            ->assertDontSee(__('booking.tracking.arrive_title'));
    }

    /** At the booked minute itself they are not late yet. */
    public function test_at_the_booked_time_the_notice_still_says_come_before(): void
    {
        $this->now('08:30');
        $mine = $this->booking('08:30');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.arrive_title'))
            ->assertDontSee(__('booking.tracking.arrive_late_title'));
    }

    public function test_a_delay_worth_announcing_replaces_the_arrival_notice(): void
    {
        $this->get($this->lateBy(60)->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.delay_title'))
            ->assertDontSee(__('booking.tracking.arrive_title'));
    }

    /** Nobody ahead yet: the same notice, in place of the first-in-line note. */
    public function test_the_first_in_line_gets_the_notice_too(): void
    {
        $this->now('08:00');
        $mine = $this->booking('08:30');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.arrive_title'))
            ->assertDontSee(__('booking.tracking.first_in_line_note', [
                'time' => '8:30 '.__('booking.tracking.am'),
                'lead' => __('messages.minutes', ['count' => (int) $this->clinic->patient_arrival_lead_minutes]),
            ]));
    }

    /** Already in the clinic: telling them to come would make no sense. */
    public function test_a_patient_already_in_the_clinic_is_not_asked_to_come(): void
    {
        $this->now('08:00');
        $this->booking('08:00');
        $mine = $this->booking('08:30', attributes: ['status' => BookingStatus::ARRIVED]);

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.arrive_title'))
            ->assertSee(__('booking.tracking.your_status'));
    }

    public function test_a_finished_visit_gets_no_notice(): void
    {
        $this->now('09:00');
        $mine = $this->booking('08:30', attributes: ['status' => BookingStatus::DONE]);

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.arrive_title'));
    }

    public function test_with_the_estimate_switched_off_there_is_no_arrival_notice(): void
    {
        config(['clinic.tracking.show_estimate' => false]);

        $this->now('08:00');
        $this->booking('08:00');
        $mine = $this->booking('08:30');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.arrive_title'))
            ->assertSee(__('booking.tracking.booked_at'));
    }

    public function test_just_over_the_threshold_shows(): void
    {
        // 50 − 30 = 20: shown, expected 09:20.
        $this->get($this->lateBy(50)->trackingUrl())
            ->assertOk()
            ->assertSee(DelayLabel::for(20))
            ->assertSee('9:20 '.__('booking.tracking.am'));
    }

    public function test_the_delay_is_rounded_to_five_minutes(): void
    {
        // 48 − 30 = 18 → 20, shown.
        $this->get($this->lateBy(48)->trackingUrl())
            ->assertOk()
            ->assertSee(DelayLabel::for(20));
    }

    public function test_rounding_down_can_drop_it_under_the_threshold(): void
    {
        // 47 − 30 = 17 → 15, not more than 15.
        $this->get($this->lateBy(47)->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.delay_title'));
    }

    /** Hours and minutes in words, not a pile of minutes. */
    public function test_a_long_delay_reads_as_hours_and_minutes(): void
    {
        // 130 − 30 = 100: "ساعة و40 دقيقة", never "100 دقيقة".
        $this->get($this->lateBy(130)->trackingUrl())
            ->assertOk()
            ->assertSee(DelayLabel::for(100))
            ->assertDontSee(__('messages.minutes', ['count' => 100]));
    }

    public function test_each_clinic_can_set_its_own_reduction_and_threshold(): void
    {
        $this->clinic->update([
            'tracking_delay_reduction_minutes' => 10,
            'tracking_delay_threshold_minutes' => 5,
        ]);

        // 45 − 10 = 35, more than 5: expected 09:35.
        $this->get($this->lateBy(45)->trackingUrl())
            ->assertOk()
            ->assertSee(DelayLabel::for(35))
            ->assertSee('9:35 '.__('booking.tracking.am'));
    }

    public function test_with_the_estimate_switched_off_there_is_no_notice(): void
    {
        config(['clinic.tracking.show_estimate' => false]);

        $this->get($this->lateBy(120)->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.delay_title'))
            // Production's box, as before.
            ->assertSee(__('booking.tracking.booked_at'));
    }

    /** With nobody ahead, being past the time is the patient's own lateness. */
    public function test_nobody_ahead_means_no_notice_even_past_the_appointment(): void
    {
        $this->now('10:30');
        $mine = $this->booking('09:00');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.delay_title'));
    }

    public function test_an_emergency_has_no_appointment_to_be_late_against(): void
    {
        $this->now('10:00');
        $this->booking('08:00', 60, ['booking_kind' => BookingKind::EMERGENCY, 'status' => BookingStatus::ARRIVED, 'arrived_at' => now()->subHour(), 'queue_entered_at' => now()->subHour()]);
        $mine = $this->booking('09:00', 20, [
            'booking_kind' => BookingKind::EMERGENCY,
            'status' => BookingStatus::ARRIVED,
            'arrived_at' => now(),
            'queue_entered_at' => now(),
            'start_at' => null,
            'end_at' => null,
        ]);

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertDontSee(__('booking.tracking.delay_title'));
    }

    public function test_the_expected_time_after_midnight_says_so(): void
    {
        // Booked 23:50 under the 3rd; an hour of visits ahead at 23:50.
        Carbon::setTestNow(Carbon::parse('2026-09-03 23:50', 'Africa/Cairo'));
        $this->booking('22:00', 60);
        $mine = $this->booking('23:50');

        // Gap 60 − 30 = 30: expected 00:20, after midnight.
        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee('12:20 '.__('booking.tracking.am'))
            ->assertSeeInOrder([__('booking.tracking.delay_expected'), '12:20', __('schedule.after_midnight')]);
    }

    public function test_the_doctor_card_is_slim_on_the_tracking_page_only(): void
    {
        $mine = $this->lateBy(60);

        $this->get($mine->trackingUrl())->assertOk()->assertSee('class="dcard is-slim"', escape: false);
        $this->clinic->update(['slug' => 'dr-sara']);
        $this->get(route('landing', 'dr-sara'))->assertOk()
            ->assertSee('class="dcard"', escape: false)
            ->assertDontSee('class="dcard is-slim"', escape: false);
    }
}
