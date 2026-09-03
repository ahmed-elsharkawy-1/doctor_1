<?php

namespace Tests\Feature\Web;

use App\Enums\BookingKind;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class BookingTrackingPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $time = '09:40', int $duration = 20): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 '.$time, $this->clinic->timezone), $duration)
            ->create();
    }

    public function test_a_booking_is_given_a_tracking_token_when_it_is_created(): void
    {
        $booking = $this->booking();

        $this->assertNotNull($booking->tracking_token);
        $this->assertSame(
            config('clinic.tracking.token_bytes') * 2,
            strlen($booking->tracking_token),
        );
    }

    public function test_two_bookings_never_share_a_token(): void
    {
        $this->assertNotSame(
            $this->booking('09:00')->tracking_token,
            $this->booking('09:40')->tracking_token,
        );
    }

    public function test_the_page_opens_without_logging_in(): void
    {
        $booking = $this->booking();

        $this->get($booking->trackingUrl())
            ->assertOk()
            ->assertSee($this->clinic->name)
            ->assertSee($booking->patient->code);
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/'.config('clinic.tracking.path').'/'.str_repeat('a', 32))
            ->assertNotFound();
    }

    public function test_it_shows_how_many_patients_are_ahead(): void
    {
        $this->booking('09:00');
        $this->booking('09:20');
        $mine = $this->booking('09:40');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.waiting_count'))
            ->assertSee(__('booking.tracking.emergency_notice'));
    }

    public function test_the_first_patient_is_told_it_is_their_turn(): void
    {
        $mine = $this->booking('09:00');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.your_turn'))
            ->assertDontSee('<div class="count">', escape: false);
    }

    public function test_a_finished_visit_says_so(): void
    {
        $mine = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))->done()->create();

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.done'))
            ->assertDontSee('<div class="count">', escape: false);
    }

    public function test_a_cancelled_booking_says_so(): void
    {
        $mine = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))->cancelled()->create();

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.cancelled'));
    }

    public function test_a_no_show_booking_says_so(): void
    {
        $mine = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))->noShow()->create();

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.no_show'));
    }

    public function test_the_count_is_withheld_before_the_day_of_the_visit(): void
    {
        $mine = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-04 09:00', $this->clinic->timezone))
            ->create();

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.not_today'))
            ->assertDontSee('<div class="count">', escape: false);
    }

    public function test_a_waiting_page_refreshes_itself(): void
    {
        $this->booking('09:00');
        $mine = $this->booking('09:40');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee('http-equiv="refresh"', escape: false);
    }

    public function test_a_finished_page_does_not_refresh_itself(): void
    {
        $mine = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))->done()->create();

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertDontSee('http-equiv="refresh"', escape: false);
    }

    public function test_an_emergency_booking_is_flagged_on_the_page(): void
    {
        $mine = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))
            ->create(['booking_kind' => BookingKind::EMERGENCY]);

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.kind.emergency'));
    }

    public function test_the_page_is_kept_out_of_search_engines(): void
    {
        $this->get($this->booking()->trackingUrl())
            ->assertOk()
            ->assertSee('noindex', escape: false);
    }

    public function test_the_token_is_never_serialised_with_the_booking(): void
    {
        $this->assertArrayNotHasKey('tracking_token', $this->booking()->toArray());
    }
}
