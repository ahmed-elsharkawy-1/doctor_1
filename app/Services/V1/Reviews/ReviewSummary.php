<?php

namespace App\Services\V1\Reviews;

use App\Enums\ReviewRating;

/**
 * A clinic's reviews in numbers — the card at the top of the reviews screen.
 *
 * "Satisfied" is very good plus good: everyone who did not leave notes.
 */
final class ReviewSummary
{
    /**
     * @param  array<string, int>  $counts  rating value => reviews, every rating present
     */
    public function __construct(public readonly array $counts) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function count(ReviewRating $rating): int
    {
        return $this->counts[$rating->value] ?? 0;
    }

    public function satisfied(): int
    {
        return $this->count(ReviewRating::VERY_GOOD) + $this->count(ReviewRating::GOOD);
    }

    /** Whole percent, or null with no reviews yet — there is nothing to be 0% of. */
    public function satisfiedPercent(): ?int
    {
        $total = $this->total();

        return $total === 0 ? null : (int) round($this->satisfied() / $total * 100);
    }
}
