<?php

namespace Tests\Feature\Web;

use App\Enums\BookingKind;
use App\Enums\DoctorSex;
use App\Models\Booking;
use App\Services\V1\Queue\BookingStatusService;
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

    /** The rendered length of the progress arc. */
    private function arcLength(string $html): float
    {
        preg_match('/class="arc".*?stroke-dasharray="([\d.]+)/s', $html, $matches);

        return (float) ($matches[1] ?? 0);
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

    /*
    |--------------------------------------------------------------------------
    | The redesigned page
    |--------------------------------------------------------------------------
    */

    public function test_it_shows_who_the_patient_is_seeing(): void
    {
        $doctor = $this->clinic->doctor;
        $doctor->update(['title' => 'أخصائية النساء والتوليد', 'sex' => DoctorSex::FEMALE]);
        $this->clinic->update(['address' => '12 شارع الجمهورية، المنصورة']);

        $this->get($this->booking()->trackingUrl())
            ->assertOk()
            ->assertSee($doctor->name)
            ->assertSee('أخصائية النساء والتوليد')
            ->assertSee('12 شارع الجمهورية، المنصورة')
            ->assertSee(DoctorSex::FEMALE->avatarUrl(), escape: false);
    }

    public function test_the_emergency_tile_is_shown_alongside_the_others(): void
    {
        $this->booking('09:00');
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:10', $this->clinic->timezone))
            ->create(['booking_kind' => BookingKind::EMERGENCY]);
        $mine = $this->booking('09:40');

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.total'))
            ->assertSee(__('booking.tracking.normal'))
            ->assertSee(__('booking.tracking.emergency'));
    }

    public function test_the_ring_fills_as_the_patient_moves_up(): void
    {
        $first = $this->booking('09:00');
        $this->booking('09:20');
        $mine = $this->booking('09:40');

        $behind = $this->arcLength($this->get($mine->trackingUrl())->getContent());

        // One patient ahead is finished, so the arc must advance.
        app(BookingStatusService::class)->arrive($first);
        app(BookingStatusService::class)->callIn($first);
        app(BookingStatusService::class)->complete($first);

        $ahead = $this->arcLength($this->get($mine->trackingUrl())->getContent());

        $this->assertGreaterThan($behind, $ahead);
    }

    public function test_a_finished_visit_fills_the_ring_completely(): void
    {
        $mine = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))->done()->create();

        $html = $this->get($mine->trackingUrl())->getContent();

        $this->assertStringContainsString('is-done', $html);
        // The arc is the full circumference, to the rounding the view applies.
        $this->assertEqualsWithDelta(2 * M_PI * 78, $this->arcLength($html), 0.05);
    }

    public function test_the_time_is_shown_as_a_range(): void
    {
        $mine = $this->booking('09:40', 20);

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee('9:40 '.__('booking.tracking.am').' - 10:00 '.__('booking.tracking.am'));
    }

    public function test_an_emergency_says_so_instead_of_a_time(): void
    {
        $mine = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))
            ->create(['booking_kind' => BookingKind::EMERGENCY, 'start_at' => null, 'end_at' => null]);

        // An emergency holds no slot, so there is no time to print.
        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.kind.emergency'));
    }

    public function test_the_booking_type_chip_shows_the_visit_type(): void
    {
        $mine = $this->booking();
        $visitType = $mine->visitType;

        $this->get($mine->trackingUrl())
            ->assertOk()
            ->assertSee(__('booking.tracking.booking_type'))
            ->assertSee($visitType->name);
    }

    public function test_the_date_reads_with_its_day_name(): void
    {
        $this->get($this->booking()->trackingUrl())
            ->assertOk()
            ->assertSee(Carbon::parse('2026-09-03')->locale('ar')->translatedFormat('l، j/n/Y'));
    }

    public function test_the_token_is_never_serialised_with_the_booking(): void
    {
        $this->assertArrayNotHasKey('tracking_token', $this->booking()->toArray());
    }
}
