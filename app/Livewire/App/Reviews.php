<?php

namespace App\Livewire\App;

use App\Enums\ReviewRating;
use App\Services\Results\V1\Reviews\ReviewListItemResult;
use App\Services\Results\V1\Reviews\ReviewSummaryResult;
use App\Services\V1\Reviews\ClinicReviewsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * "التقييمات" — what patients said about their visits.
 *
 * A summary card, a filter, and the list under it, newest first; tapping a
 * review opens its details with call and WhatsApp buttons. The data is the
 * same ClinicReviewsService answer GET /api/v1/reviews gives the mobile app,
 * in the same shape, so the two screens cannot drift apart.
 */
class Reviews extends ClinicComponent
{
    /** How many more each "load more" shows. */
    private const PAGE = 20;

    /** The filter chip; empty for "الكل". */
    #[Url(as: 'rating', except: '')]
    public string $rating = '';

    public int $limit = self::PAGE;

    /** The review whose details are open. */
    public ?int $openId = null;

    public function mount(): void
    {
        $this->requireAbility('patients.view');

        // Whatever the address bar carried, only a real rating filters.
        if (ReviewRating::tryFrom($this->rating) === null) {
            $this->rating = '';
        }
    }

    public function render(): View
    {
        $this->requireAbility('patients.view');

        $service = app(ClinicReviewsService::class);
        $clinic = $this->clinic();
        $page = $service->list($clinic, ReviewRating::tryFrom($this->rating), $this->limit, 1);
        $items = ReviewListItemResult::collection($page->getCollection());

        return view('livewire.app.reviews', [
            'summary' => (new ReviewSummaryResult($service->summary($clinic)))->toArray(),
            'items' => $items,
            'shown' => $page->total(),
            'hasMore' => $page->hasMorePages(),
            'opened' => collect($items)->firstWhere('id', $this->openId),
        ])->title(__('app.reviews.title'));
    }

    public function filter(string $rating): void
    {
        $this->rating = ReviewRating::tryFrom($rating)?->value ?? '';
        $this->limit = self::PAGE;
        $this->openId = null;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    public function open(int $reviewId): void
    {
        $this->openId = $reviewId;
    }

    public function close(): void
    {
        $this->openId = null;
    }
}
