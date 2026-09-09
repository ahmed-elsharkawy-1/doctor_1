<?php

namespace Tests\Feature\Filament;

use App\Enums\ReviewRating;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ReviewsPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();

        $this->admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    }

    private function review(ReviewRating $rating, ?string $comment = null): BookingReview
    {
        $booking = Booking::factory()->forClinic($this->clinic)->done()->create();

        return BookingReview::create([
            'booking_id' => $booking->id,
            'clinic_id' => $booking->clinic_id,
            'doctor_id' => $booking->doctor_id,
            'patient_id' => $booking->patient_id,
            'rating' => $rating,
            'comment' => $comment,
            'submitted_at' => now(),
        ]);
    }

    public function test_the_platform_operator_can_read_the_reviews(): void
    {
        $this->review(ReviewRating::VERY_GOOD, 'تجربة ممتازة');

        Livewire::actingAs($this->admin)
            ->test(ListReviews::class)
            ->assertOk()
            ->assertSee('تجربة ممتازة');
    }

    public function test_a_clinic_account_cannot_reach_the_page(): void
    {
        // Clinics work from the mobile app and the web app; the panel is the
        // platform operator's alone.
        $this->actingAs($this->owner)
            ->get('/'.config('clinic.panel.path').'/reviews')
            ->assertForbidden();
    }

    public function test_reviews_can_be_filtered_by_rating(): void
    {
        $good = $this->review(ReviewRating::VERY_GOOD, 'كان ممتاز');
        $bad = $this->review(ReviewRating::BAD, 'الانتظار كان طويل');

        Livewire::actingAs($this->admin)
            ->test(ListReviews::class)
            ->filterTable('rating', ReviewRating::BAD->value)
            ->assertCanSeeTableRecords([$bad])
            ->assertCanNotSeeTableRecords([$good]);
    }

    public function test_the_page_counts_each_rating(): void
    {
        $this->review(ReviewRating::VERY_GOOD);
        $this->review(ReviewRating::VERY_GOOD);
        $this->review(ReviewRating::BAD);

        $subheading = Livewire::actingAs($this->admin)
            ->test(ListReviews::class)
            ->instance()
            ->getSubheading();

        $this->assertStringContainsString(ReviewRating::VERY_GOOD->label().': 2', $subheading);
        $this->assertStringContainsString(ReviewRating::BAD->label().': 1', $subheading);
    }

    public function test_reviews_cannot_be_created_or_edited_from_the_panel(): void
    {
        // A review is the patient's word — nobody here gets to rewrite it.
        $this->assertFalse(ReviewResource::canCreate());
        $this->assertFalse(
            ReviewResource::canEdit($this->review(ReviewRating::GOOD)),
        );
    }
}
