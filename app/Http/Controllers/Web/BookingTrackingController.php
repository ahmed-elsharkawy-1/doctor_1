<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\V1\Queue\QueuePositionService;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * The page a patient opens from the link the clinic sends them.
 *
 * Read-only and unauthenticated — the token in the URL is the whole secret.
 * It reuses QueuePositionService, the same service behind the clinic's own
 * queue screen, so the two can never disagree.
 */
class BookingTrackingController extends Controller
{
    public function __invoke(Booking $booking, QueuePositionService $positions): View
    {
        // Patients are not sent an Accept-Language header worth trusting.
        App::setLocale(config('clinic.api.default_locale'));

        $booking->load(['patient', 'clinic', 'visitType']);

        return view('tracking.show', [
            'booking' => $booking,
            'clinic' => $booking->clinic,
            'position' => $positions->for($booking),
            'refreshSeconds' => config('clinic.tracking.refresh_seconds'),
        ]);
    }
}
