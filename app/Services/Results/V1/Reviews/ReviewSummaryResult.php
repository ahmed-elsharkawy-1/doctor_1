<?php

namespace App\Services\Results\V1\Reviews;

use App\Enums\ReviewRating;
use App\Services\Results\ServiceResult;
use App\Services\V1\Reviews\ReviewSummary;

/**
 * The card at the top of the reviews screen: the whole picture, never the
 * filtered one.
 */
final class ReviewSummaryResult extends ServiceResult
{
    public function __construct(private readonly ReviewSummary $summary) {}

    public function toArray(): array
    {
        return [
            'total' => $this->summary->total(),
            // Very good plus good. Null with no reviews yet.
            'satisfied_percent' => $this->summary->satisfiedPercent(),
            'ratings' => array_map(fn (ReviewRating $rating): array => [
                'value' => $rating->value,
                'display' => $rating->staffLabel(),
                'count' => $this->summary->count($rating),
            ], ReviewRating::ordered()),
        ];
    }
}
