<?php

namespace Tests\Feature\Web;

use App\Enums\ReviewRating;
use App\Livewire\App\Reviews;
use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The web side of "التقييمات". What counts and in which order is
 * ClinicReviewsService's, proven in ListReviewsTest; this proves the screen
 * shows that answer, the same one the mobile app gets.
 */
class ClinicAppReviewsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private int $phones = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function review(ReviewRating $rating, ?string $comment = null, string $submittedAt = '2026-10-24 22:00'): BookingReview
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => '+2010123456'.str_pad((string) $this->phones++, 2, '0', STR_PAD_LEFT),
        ]);
        $booking = Booking::factory()->forClinic($this->clinic)->done()
            ->at(Carbon::parse('2026-10-22 18:30'))
            ->create(['patient_id' => $patient->id]);

        return BookingReview::create([
            'booking_id' => $booking->id,
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $booking->doctor_id,
            'patient_id' => $patient->id,
            'rating' => $rating,
            'comment' => $comment,
            'submitted_at' => Carbon::parse($submittedAt),
        ]);
    }

    public function test_the_screen_is_in_the_navigation_and_opens(): void
    {
        $this->actingAs($this->secretary)
            ->get(route('app.reviews'))
            ->assertOk()
            ->assertSee(__('app.nav.reviews'))
            ->assertSee(__('app.reviews.satisfied'));
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('app.reviews'))->assertRedirect(route('app.login'));
    }

    public function test_it_shows_the_summary_and_the_reviews(): void
    {
        $this->review(ReviewRating::VERY_GOOD);
        $this->review(ReviewRating::VERY_GOOD);
        $this->review(ReviewRating::GOOD);
        $bad = $this->review(ReviewRating::BAD, 'الانتظار كان طويل');

        Livewire::actingAs($this->owner)
            ->test(Reviews::class)
            ->assertViewHas('summary', fn ($summary) => $summary['total'] === 4 && $summary['satisfied_percent'] === 75)
            ->assertViewHas('items', fn ($items) => count($items) === 4)
            ->assertSee('75%')
            ->assertSee($bad->patient->name)
            ->assertSee('الانتظار كان طويل');
    }

    public function test_the_filter_narrows_the_list_but_never_the_summary(): void
    {
        $this->review(ReviewRating::VERY_GOOD);
        $bad = $this->review(ReviewRating::BAD, 'ملاحظة');

        Livewire::actingAs($this->owner)
            ->test(Reviews::class)
            ->call('filter', 'bad')
            ->assertSet('rating', 'bad')
            ->assertViewHas('items', fn ($items) => array_column($items, 'id') === [$bad->id])
            ->assertViewHas('summary', fn ($summary) => $summary['total'] === 2)
            ->assertSee(__('app.reviews.count_of', ['rating' => __('review.staff_rating.bad'), 'count' => 1]))
            ->call('filter', 'nonsense')
            ->assertSet('rating', '')
            ->assertViewHas('items', fn ($items) => count($items) === 2);
    }

    public function test_an_unknown_rating_in_the_address_shows_everything(): void
    {
        $this->review(ReviewRating::GOOD);

        Livewire::withQueryParams(['rating' => 'excellent'])
            ->actingAs($this->owner)
            ->test(Reviews::class)
            ->assertSet('rating', '')
            ->assertViewHas('items', fn ($items) => count($items) === 1);
    }

    public function test_load_more_shows_the_next_reviews(): void
    {
        foreach (range(1, 25) as $i) {
            $this->review(ReviewRating::GOOD, submittedAt: '2026-10-24 22:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        Livewire::actingAs($this->owner)
            ->test(Reviews::class)
            ->assertViewHas('items', fn ($items) => count($items) === 20)
            ->assertViewHas('hasMore', true)
            ->call('loadMore')
            ->assertViewHas('items', fn ($items) => count($items) === 25)
            ->assertViewHas('hasMore', false);
    }

    public function test_a_review_opens_its_details_with_call_and_whatsapp(): void
    {
        $review = $this->review(ReviewRating::BAD, 'الانتظار كان طويل جدًا');
        $phone = $review->patient->phone;

        Livewire::actingAs($this->owner)
            ->test(Reviews::class)
            ->assertDontSee(__('app.reviews.call'))
            ->call('open', $review->id)
            ->assertSee(__('app.reviews.note'))
            ->assertSee('href="tel:'.$phone.'"', escape: false)
            ->assertSee('href="https://wa.me/'.ltrim($phone, '+').'"', escape: false)
            ->assertSee($review->booking->visitType->name)
            ->call('close')
            ->assertDontSee(__('app.reviews.call'));
    }

    public function test_with_no_reviews_it_says_so(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Reviews::class)
            ->assertSee(__('app.reviews.none'))
            ->assertDontSee(__('app.reviews.load_more'));
    }

    /** The two screens read one service in one shape, so they cannot drift. */
    public function test_the_web_page_shows_exactly_what_the_api_returns(): void
    {
        $this->review(ReviewRating::VERY_GOOD, submittedAt: '2026-10-23 20:00');
        $this->review(ReviewRating::BAD, 'ملاحظة', '2026-10-24 21:00');
        $this->review(ReviewRating::GOOD);

        Sanctum::actingAs($this->owner);
        $api = $this->getJson(route('api.v1.reviews.index'))->assertOk()->json('data');

        Livewire::actingAs($this->owner)
            ->test(Reviews::class)
            ->assertViewHas('summary', fn ($summary) => $summary == $api['summary'])
            ->assertViewHas('items', fn ($items) => $items == $api['items']);
    }
}
