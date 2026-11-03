<?php

namespace Tests\Feature\Web;

use App\Enums\ReviewRating;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Queue\BookingStatusService;
use App\Services\V1\Reviews\BookingReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class BookingReviewPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $state = 'done'): Booking
    {
        $factory = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-09 09:00', $this->clinic->timezone));

        return match ($state) {
            'done' => $factory->done()->create(),
            'cancelled' => $factory->cancelled()->create(),
            'no_show' => $factory->noShow()->create(),
            default => $factory->create(),
        };
    }

    private function url(Booking $booking): string
    {
        return $booking->reviewUrl();
    }

    /*
    |--------------------------------------------------------------------------
    | Getting to the page
    |--------------------------------------------------------------------------
    */

    public function test_the_page_opens_without_logging_in(): void
    {
        $booking = $this->booking();

        $this->get($this->url($booking))
            ->assertOk()
            ->assertSee(__('review.title'))
            ->assertSee(__('review.rating.very_good'))
            ->assertSee(__('review.rating.good'))
            ->assertSee(__('review.rating.bad'));
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/'.config('clinic.review.path').'/'.str_repeat('z', 32))
            ->assertNotFound();
    }

    /**
     * The patient rates the service, not the doctor, so the page carries no
     * doctor card — no photo, no name, no specialty.
     */
    public function test_it_does_not_show_a_doctor_card(): void
    {
        $html = $this->get($this->url($this->booking()))->assertOk()->getContent();

        $body = substr($html, strpos($html, '<body>'));

        $this->assertStringNotContainsString('class="dcard', $body);
        $this->assertStringNotContainsString($this->clinic->doctor->name, $body);
        $this->assertStringNotContainsString($this->clinic->doctor->avatarUrl(), $body);
    }

    public function test_the_page_is_kept_out_of_search_engines(): void
    {
        $this->get($this->url($this->booking()))
            ->assertOk()
            ->assertSee('noindex', escape: false);
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses
    |--------------------------------------------------------------------------
    */

    public function test_a_visit_still_in_the_queue_cannot_be_rated_yet(): void
    {
        $this->get($this->url($this->booking('booked')))
            ->assertOk()
            ->assertSee(__('review.not_done_title'))
            ->assertDontSee(__('review.choose'));
    }

    public function test_a_cancelled_booking_has_nothing_to_rate(): void
    {
        $this->get($this->url($this->booking('cancelled')))
            ->assertOk()
            ->assertSee(__('review.unavailable_title'))
            ->assertDontSee(__('review.choose'));
    }

    public function test_a_no_show_has_nothing_to_rate(): void
    {
        $this->get($this->url($this->booking('no_show')))
            ->assertOk()
            ->assertSee(__('review.unavailable_title'));
    }

    public function test_posting_to_an_unfinished_visit_stores_nothing(): void
    {
        $booking = $this->booking('booked');

        $this->post($this->url($booking), ['rating' => ReviewRating::GOOD->value])
            ->assertRedirect($this->url($booking));

        $this->assertSame(0, BookingReview::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Submitting
    |--------------------------------------------------------------------------
    */

    public function test_a_rating_and_a_comment_are_stored(): void
    {
        $booking = $this->booking();

        $this->post($this->url($booking), [
            'rating' => ReviewRating::VERY_GOOD->value,
            'comment' => 'الدكتورة شرحت كل حاجة بالتفصيل.',
        ])->assertRedirect($this->url($booking));

        $review = BookingReview::firstOrFail();

        $this->assertSame($booking->id, $review->booking_id);
        $this->assertSame($this->clinic->id, $review->clinic_id);
        $this->assertSame($booking->doctor_id, $review->doctor_id);
        $this->assertSame($booking->patient_id, $review->patient_id);
        $this->assertSame(ReviewRating::VERY_GOOD, $review->rating);
        $this->assertSame('الدكتورة شرحت كل حاجة بالتفصيل.', $review->comment);
        $this->assertNotNull($review->submitted_at);
    }

    /** "Very good" and "good" need no explanation. */
    public function test_the_comment_is_optional_for_a_positive_rating(): void
    {
        foreach ([ReviewRating::VERY_GOOD, ReviewRating::GOOD] as $rating) {
            $booking = $this->booking();

            $this->post($this->url($booking), ['rating' => $rating->value])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $this->assertNull($booking->review()->firstOrFail()->comment);
        }
    }

    /**
     * "I have some notes" is only useful with the notes: without them the
     * team knows something went wrong and nothing about what.
     */
    public function test_the_comment_is_required_with_some_notes(): void
    {
        $booking = $this->booking();

        $this->post($this->url($booking), ['rating' => ReviewRating::BAD->value, 'comment' => '   '])
            ->assertSessionHasErrors(['comment' => __('review.comment_required')]);

        $this->assertSame(0, BookingReview::count());

        $this->post($this->url($booking), ['rating' => ReviewRating::BAD->value, 'comment' => 'تأكيد الحجز وصل متأخر.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('تأكيد الحجز وصل متأخر.', BookingReview::sole()->comment);
    }

    /** The rule lives in the service, so no other caller can skip it. */
    public function test_the_service_refuses_some_notes_without_a_comment(): void
    {
        $this->expectException(ApiException::class);

        app(BookingReviewService::class)->submit($this->booking(), ReviewRating::BAD, '  ');
    }

    /** A refused form says why, beside the field — not a silent reload. */
    public function test_a_refused_form_shows_its_errors(): void
    {
        $booking = $this->booking();

        $this->from($this->url($booking))
            ->followingRedirects()
            ->post($this->url($booking), ['rating' => ReviewRating::BAD->value])
            ->assertSee(__('review.comment_required'))
            ->assertSee(ReviewRating::BAD->label());

        // The choice survives the round trip, so the patient only adds the notes.
        $this->assertMatchesRegularExpression(
            '/value="'.ReviewRating::BAD->value.'"\s+checked/',
            $this->from($this->url($booking))->followingRedirects()
                ->post($this->url($booking), ['rating' => ReviewRating::BAD->value])->getContent(),
        );

        $this->from($this->url($booking))
            ->followingRedirects()
            ->post($this->url($booking), [])
            ->assertSee(__('review.rating_required'));
    }

    /** The page asks about the booking experience, not the doctor. */
    public function test_it_asks_about_the_booking_experience(): void
    {
        $this->get($this->url($this->booking()))
            ->assertSee(__('review.title'))
            ->assertSee(__('review.comment'))
            ->assertSee(__('review.rating.very_good'))
            ->assertSee(__('review.rating.bad'))
            // The page knows which choice makes the notes required.
            ->assertSee('data-requires-comment="'.ReviewRating::BAD->value.'"', escape: false);
    }

    public function test_a_rating_is_required(): void
    {
        $booking = $this->booking();

        $this->post($this->url($booking), ['comment' => 'بدون تقييم'])
            ->assertSessionHasErrors('rating');

        $this->assertSame(0, BookingReview::count());
    }

    public function test_an_invented_rating_is_refused(): void
    {
        $booking = $this->booking();

        $this->post($this->url($booking), ['rating' => 'excellent'])
            ->assertSessionHasErrors('rating');

        $this->assertSame(0, BookingReview::count());
    }

    public function test_an_over_long_comment_is_refused(): void
    {
        $booking = $this->booking();

        $this->post($this->url($booking), [
            'rating' => ReviewRating::GOOD->value,
            'comment' => str_repeat('ا', config('clinic.review.comment_max') + 1),
        ])->assertSessionHasErrors('comment');

        $this->assertSame(0, BookingReview::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Only once
    |--------------------------------------------------------------------------
    */

    public function test_a_second_submission_never_overwrites_the_first(): void
    {
        $booking = $this->booking();

        $this->post($this->url($booking), [
            'rating' => ReviewRating::VERY_GOOD->value,
            'comment' => 'الأول',
        ]);

        $this->post($this->url($booking), [
            'rating' => ReviewRating::BAD->value,
            'comment' => 'التاني',
        ]);

        $this->assertSame(1, BookingReview::count());

        $review = BookingReview::firstOrFail();
        $this->assertSame(ReviewRating::VERY_GOOD, $review->rating);
        $this->assertSame('الأول', $review->comment);
    }

    public function test_once_rated_the_page_shows_the_answer_back(): void
    {
        $booking = $this->booking();

        $this->post($this->url($booking), [
            'rating' => ReviewRating::GOOD->value,
            'comment' => 'كل حاجة تمام',
        ]);

        $this->get($this->url($booking))
            ->assertOk()
            ->assertSee(__('review.thanks_title'))
            ->assertSee(__('review.rating.good'))
            ->assertSee('كل حاجة تمام')
            // The form is gone; there is nothing left to answer.
            ->assertDontSee(__('review.choose'));
    }

    /*
    |--------------------------------------------------------------------------
    | The message that brings them here
    |--------------------------------------------------------------------------
    */

    public function test_finishing_a_visit_sends_the_review_invitation(): void
    {
        MessageTemplate::updateOrCreate(
            ['key' => WhatsAppMessagingService::VISIT_COMPLETED_KEY],
            [
                'category' => 'utility',
                'is_broadcast' => false,
                'body_ar' => 'شكراً لزيارتك {{1}} في {{2}}.',
                'provider_template_name' => WhatsAppMessagingService::VISIT_COMPLETED_KEY,
                'is_active' => true,
            ],
        );

        $booking = $this->booking('booked');
        $statuses = app(BookingStatusService::class);

        $statuses->arrive($booking);
        $statuses->callIn($booking);

        $this->assertSame(0, OutboundMessage::where('template_key', WhatsAppMessagingService::VISIT_COMPLETED_KEY)->count());

        $statuses->complete($booking);

        $this->assertSame(1, OutboundMessage::where('template_key', WhatsAppMessagingService::VISIT_COMPLETED_KEY)->count());
    }

    public function test_a_missing_template_never_blocks_the_status_change(): void
    {
        MessageTemplate::where('key', WhatsAppMessagingService::VISIT_COMPLETED_KEY)->delete();

        $booking = $this->booking('booked');
        $statuses = app(BookingStatusService::class);

        $statuses->arrive($booking);
        $statuses->callIn($booking);
        $statuses->complete($booking);

        $this->assertSame('done', $booking->fresh()->status->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Booking again
    |--------------------------------------------------------------------------
    */

    public function test_a_finished_visit_links_back_to_the_doctor_page(): void
    {
        $this->clinic->update(['slug' => 'dr-sara']);
        $this->get($this->url($this->booking()))
            ->assertOk()
            ->assertSee(__('booking.tracking.book_again'))
            ->assertSee('href="'.route('landing', $this->clinic->slug).'"', false);
    }

    public function test_it_links_to_the_booking_page_when_the_clinic_takes_online_bookings(): void
    {
        $this->clinic->update(['slug' => 'dr-sara', 'self_booking_enabled' => true]);

        $this->get($this->url($this->booking()))
            ->assertOk()
            ->assertSee('href="'.route('patient.book', $this->clinic->slug).'"', false);
    }

    public function test_the_link_stays_after_the_review_is_sent_and_on_a_cancelled_booking(): void
    {
        $this->clinic->update(['slug' => 'dr-sara']);
        $reviewed = $this->booking();
        app(BookingReviewService::class)->submit($reviewed, ReviewRating::VERY_GOOD, null);

        $this->get($this->url($reviewed))->assertSee(__('booking.tracking.book_again'));
        $this->get($this->url($this->booking('cancelled')))->assertSee(__('booking.tracking.book_again'));
        $this->get($this->url($this->booking('no_show')))->assertSee(__('booking.tracking.book_again'));
    }

    public function test_a_visit_still_ahead_offers_no_second_booking(): void
    {
        $this->clinic->update(['slug' => 'dr-sara']);
        $this->get($this->url($this->booking('booked')))
            ->assertOk()
            ->assertDontSee(__('booking.tracking.book_again'));
    }

    public function test_a_clinic_with_no_public_page_offers_no_link(): void
    {
        $this->get($this->url($this->booking()))
            ->assertOk()
            ->assertDontSee(__('booking.tracking.book_again'));
    }

    public function test_a_deactivated_clinic_offers_no_link(): void
    {
        $this->clinic->update(['slug' => 'dr-sara']);
        $booking = $this->booking();
        $this->clinic->update(['is_active' => false]);

        $this->get($this->url($booking))
            ->assertOk()
            ->assertDontSee(__('booking.tracking.book_again'));
    }
}
