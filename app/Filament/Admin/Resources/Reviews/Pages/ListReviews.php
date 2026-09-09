<?php

namespace App\Filament\Admin\Resources\Reviews\Pages;

use App\Enums\ReviewRating;
use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Models\BookingReview;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    /**
     * A headline count per rating, so the shape of the feedback is visible
     * before anyone starts reading individual notes.
     */
    public function getSubheading(): ?string
    {
        $counts = BookingReview::query()
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        if ($counts->sum() === 0) {
            return null;
        }

        $parts = [];

        foreach (ReviewRating::ordered() as $rating) {
            $parts[] = $rating->label().': '.($counts[$rating->value] ?? 0);
        }

        return implode('  ·  ', $parts);
    }
}
