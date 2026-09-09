<?php

namespace App\Http\Controllers\Web;

use App\Enums\ReviewRating;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\V1\Reviews\BookingReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * The page a patient opens from the visit-completed WhatsApp message.
 *
 * Read-only and unauthenticated, like the tracking page: the token in the URL
 * is the whole secret, and it is the same token — already this patient's
 * key to this visit.
 */
class BookingReviewController extends Controller
{
    public function __construct(private readonly BookingReviewService $reviews) {}

    public function show(Booking $booking): View
    {
        App::setLocale(config('clinic.api.default_locale'));

        return view('review.show', $this->payload($booking));
    }

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        App::setLocale(config('clinic.api.default_locale'));

        // Nothing to say about a visit that did not happen, or one already
        // rated — the page will render the right explanation on the redirect.
        if (! $this->reviews->isReviewable($booking) || $this->reviews->existing($booking) !== null) {
            return redirect()->route('booking.review', $booking->tracking_token);
        }

        $validated = $request->validate([
            'rating' => ['required', 'in:'.implode(',', ReviewRating::values())],
            'comment' => ['nullable', 'string', 'max:'.config('clinic.review.comment_max')],
        ], [
            'rating.required' => __('review.rating_required'),
            'rating.in' => __('review.rating_required'),
        ]);

        $this->reviews->submit(
            $booking,
            ReviewRating::from($validated['rating']),
            $validated['comment'] ?? null,
        );

        return redirect()->route('booking.review', $booking->tracking_token);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Booking $booking): array
    {
        $booking->load(['patient', 'clinic.doctor', 'visitType', 'review']);

        return [
            'booking' => $booking,
            'clinic' => $booking->clinic,
            'doctor' => $booking->clinic->doctor,
            'review' => $this->reviews->existing($booking),
            'reviewable' => $this->reviews->isReviewable($booking),
            'unavailable' => $this->reviews->isUnavailable($booking),
            'ratings' => ReviewRating::ordered(),
            'commentMax' => config('clinic.review.comment_max'),
        ];
    }
}
