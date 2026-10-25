<?php

namespace Tests\Feature\Api\V1\Reviews;

use App\Enums\ReviewRating;
use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ListReviewsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    /** Phones are unique per clinic; each review gets its own patient. */
    private int $phones = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00', 'Africa/Cairo'));

        $this->setUpClinic();

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function review(
        ReviewRating $rating,
        ?string $comment = null,
        string $submittedAt = '2026-10-24 22:00',
        ?Clinic $clinic = null,
    ): BookingReview {
        $clinic ??= $this->clinic;
        $patient = Patient::factory()->create([
            'clinic_id' => $clinic->id,
            'phone' => '+2010123456'.str_pad((string) $this->phones++, 2, '0', STR_PAD_LEFT),
        ]);
        $booking = Booking::factory()->forClinic($clinic)->done()
            ->at(Carbon::parse('2026-10-22 18:30'))
            ->create(['patient_id' => $patient->id]);

        return BookingReview::create([
            'booking_id' => $booking->id,
            'clinic_id' => $clinic->id,
            'doctor_id' => $booking->doctor_id,
            'patient_id' => $patient->id,
            'rating' => $rating,
            'comment' => $comment,
            'submitted_at' => Carbon::parse($submittedAt),
        ]);
    }

    public function test_the_summary_counts_every_review_and_who_is_satisfied(): void
    {
        foreach (range(1, 6) as $i) {
            $this->review(ReviewRating::VERY_GOOD);
        }
        $this->review(ReviewRating::GOOD);
        $this->review(ReviewRating::BAD, 'الانتظار كان طويل');

        $this->getJson(route('api.v1.reviews.index'))
            ->assertOk()
            ->assertJsonPath('message', __('review.list_loaded'))
            ->assertJsonPath('data.summary.total', 8)
            // (6 + 1) / 8 = 87.5 → 88
            ->assertJsonPath('data.summary.satisfied_percent', 88)
            ->assertJsonPath('data.summary.ratings.0.value', 'very_good')
            ->assertJsonPath('data.summary.ratings.0.count', 6)
            ->assertJsonPath('data.summary.ratings.1.count', 1)
            ->assertJsonPath('data.summary.ratings.2.value', 'bad')
            ->assertJsonPath('data.summary.ratings.2.display', __('review.staff_rating.bad'))
            ->assertJsonPath('data.summary.ratings.2.count', 1);
    }

    public function test_with_no_reviews_yet_the_percent_is_empty_not_zero(): void
    {
        $this->getJson(route('api.v1.reviews.index'))
            ->assertOk()
            ->assertJsonPath('data.summary.total', 0)
            ->assertJsonPath('data.summary.satisfied_percent', null)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_the_filter_narrows_the_list_but_never_the_summary(): void
    {
        $this->review(ReviewRating::VERY_GOOD);
        $this->review(ReviewRating::VERY_GOOD);
        $bad = $this->review(ReviewRating::BAD, 'ملاحظة');

        $this->getJson(route('api.v1.reviews.index', ['rating' => 'bad']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $bad->id)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.summary.total', 3);
    }

    public function test_newest_first_and_paginated(): void
    {
        $old = $this->review(ReviewRating::GOOD, submittedAt: '2026-10-20 20:00');
        $newest = $this->review(ReviewRating::GOOD, submittedAt: '2026-10-24 23:00');
        $middle = $this->review(ReviewRating::GOOD, submittedAt: '2026-10-22 21:00');

        $this->getJson(route('api.v1.reviews.index', ['per_page' => 2]))
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $newest->id)
            ->assertJsonPath('data.items.1.id', $middle->id)
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.last_page', 2);

        $this->getJson(route('api.v1.reviews.index', ['per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $old->id)
            ->assertJsonPath('data.summary.total', 3);
    }

    public function test_a_row_carries_everything_the_details_sheet_shows(): void
    {
        $review = $this->review(ReviewRating::BAD, "  الانتظار كان طويل جدًا\nوالمكان زحمة  ");
        $booking = $review->booking;

        $this->getJson(route('api.v1.reviews.index'))
            ->assertOk()
            ->assertJsonPath('data.items.0.rating.value', 'bad')
            ->assertJsonPath('data.items.0.rating.display', __('review.staff_rating.bad'))
            // Whole, not shortened: the list shortens it on screen.
            ->assertJsonPath('data.items.0.comment', "الانتظار كان طويل جدًا\nوالمكان زحمة")
            ->assertJsonPath('data.items.0.patient.name', $review->patient->name)
            ->assertJsonPath('data.items.0.patient.code', $review->patient->code)
            // Unmasked: the sheet calls and messages the patient.
            ->assertJsonPath('data.items.0.patient.phone.value', $review->patient->phone)
            ->assertJsonPath('data.items.0.patient.phone.display', PhoneNumber::tryParse($review->patient->phone)->national())
            ->assertJsonPath('data.items.0.visit.booking_id', $booking->id)
            ->assertJsonPath('data.items.0.visit.date.value', '2026-10-22')
            ->assertJsonPath('data.items.0.visit.day', __('schedule.day.thursday'))
            ->assertJsonPath('data.items.0.visit.start_time.value', '18:30')
            ->assertJsonPath('data.items.0.visit.after_midnight', false)
            ->assertJsonPath('data.items.0.visit.visit_type.name', $booking->visitType->name);
    }

    public function test_a_review_without_a_comment_is_listed_with_none(): void
    {
        $this->review(ReviewRating::GOOD, '   ');

        $this->getJson(route('api.v1.reviews.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.comment', null);
    }

    public function test_another_clinics_reviews_never_appear(): void
    {
        $this->review(ReviewRating::GOOD);
        $this->review(ReviewRating::BAD, 'ليست لنا', clinic: $this->otherClinic());

        $this->getJson(route('api.v1.reviews.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.total', 1);
    }

    public function test_an_unknown_rating_is_refused(): void
    {
        $this->getJson(route('api.v1.reviews.index', ['rating' => 'excellent']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['rating']]]);
    }

    public function test_both_the_doctor_and_the_secretary_can_read_it_but_not_a_super_admin(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson(route('api.v1.reviews.index'))->assertOk();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson(route('api.v1.reviews.index'))->assertForbidden();
    }
}
