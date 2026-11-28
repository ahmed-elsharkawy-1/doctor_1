<?php

namespace App\Services\V1\Reviews;

use App\Enums\ReviewRating;
use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\Clinic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the clinic reads about itself: its patients' reviews.
 *
 * Read only — reviews are written by patients, through BookingReviewService.
 * The same service serves GET /api/v1/reviews and the web app's reviews page.
 */
class ClinicReviewsService
{
    /**
     * Every review the clinic has — at the branches in scope (Phase 8) —
     * whatever the list is filtered to: the card at the top shows the whole
     * picture and does not change with the rating chip.
     *
     * @param  list<int>|null  $branchIds  BranchScope's answer: null for the whole clinic
     */
    public function summary(Clinic $clinic, ?array $branchIds = null): ReviewSummary
    {
        $counts = BookingReview::query()
            ->where('clinic_id', $clinic->id)
            ->when($branchIds !== null, fn ($query) => $this->atBranches($query, $branchIds))
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
    public function list(Clinic $clinic, ?ReviewRating $rating, int $perPage, ?int $page = null, ?array $branchIds = null): LengthAwarePaginator
    {
        return BookingReview::query()
            ->where('clinic_id', $clinic->id)
            ->when($branchIds !== null, fn ($query) => $this->atBranches($query, $branchIds))
            ->when($rating !== null, fn ($query) => $query->rated($rating))
            ->with(['patient', 'booking.visitType'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * A review is of the visit it was left for, and so of that booking's
     * branch.
     *
     * @param  Builder<BookingReview>  $query
     * @param  list<int>  $branchIds
     */
    private function atBranches(Builder $query, array $branchIds): void
    {
        $query->whereIn('booking_id', Booking::query()->select('id')->whereIn('branch_id', $branchIds));
    }
}
