<?php

namespace App\Services\V1\Reviews;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\ReviewRating;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\BookingReview;
use Illuminate\Support\Carbon;

/**
 * Capturing what a patient thought of a finished visit.
 *
 * The rules live here rather than in the page, so a future WhatsApp Flow
 * writing the same row cannot disagree with the web form about what counts
 * as reviewable.
 */
class BookingReviewService
{
    /**
     * Only a visit that actually happened can be rated. A cancelled or
     * no-show booking has no experience to describe, and one still in the
     * queue has not happened yet.
     */
    public function isReviewable(Booking $booking): bool
    {
        return $booking->status === BookingStatus::DONE;
    }

    /**
     * Never reached its end: cancelled, or the patient did not turn up.
     */
    public function isUnavailable(Booking $booking): bool
    {
        return in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::NO_SHOW], true);
    }

    public function existing(Booking $booking): ?BookingReview
    {
        return $booking->review;
    }

    /**
     * Records the verdict, once. A second submission for the same booking
     * returns what was already stored rather than overwriting it — the
     * unique index makes that the only honest outcome.
     *
     * @throws ApiException when the rating needs notes and none were given
     */
    public function submit(
        Booking $booking,
        ReviewRating $rating,
        ?string $comment = null,
        string $source = 'review_page',
    ): BookingReview {
        $existing = $this->existing($booking);

        if ($existing !== null) {
            return $existing;
        }

        $comment = trim((string) $comment);

        if ($comment === '' && $rating->requiresComment()) {
            throw ApiException::make(
                ApiErrorCode::REVIEW_COMMENT_REQUIRED,
                __('review.comment_required'),
                details: ['rating' => $rating->value],
                http: 422,
            );
        }

        return BookingReview::create([
            'booking_id' => $booking->id,
            'clinic_id' => $booking->clinic_id,
            'doctor_id' => $booking->doctor_id,
            'patient_id' => $booking->patient_id,
            'rating' => $rating,
            'comment' => $comment === '' ? null : $comment,
            'source' => $source,
            'submitted_at' => Carbon::now($booking->clinic?->timezone ?? config('app.timezone')),
        ]);
    }
}
