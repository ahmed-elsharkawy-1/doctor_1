<?php

namespace App\Services\V1\Reviews;

use App\Enums\ReviewRating;
use App\Models\BookingReview;
use App\Models\Clinic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * What the clinic reads about itself: its patients' reviews.
 *
 * Read only — reviews are written by patients, through BookingReviewService.
 * The same service serves GET /api/v1/reviews and the web app's reviews page.
 */
class ClinicReviewsService
{
    /**
     * Every review the clinic has, whatever the list is filtered to — the
     * card at the top shows the whole picture and does not change with it.
     */
    public function summary(Clinic $clinic): ReviewSummary
    {
        $counts = BookingReview::query()
            ->where('clinic_id', $clinic->id)
            ->selectRaw('rating, count(*) as aggregate')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating');

        return new ReviewSummary(collect(ReviewRating::ordered())
            ->mapWithKeys(fn (ReviewRating $rating): array => [$rating->value => (int) ($counts[$rating->value] ?? 0)])
            ->all());
    }

    /**
     * Newest first, optionally one rating only. Reviews without a comment are
     * listed too: a rating on its own is still a review.
     *
     * @return LengthAwarePaginator<int, BookingReview>
     */
    public function list(Clinic $clinic, ?ReviewRating $rating, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        return BookingReview::query()
            ->where('clinic_id', $clinic->id)
            ->when($rating !== null, fn ($query) => $query->rated($rating))
            ->with(['patient', 'booking.visitType'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
