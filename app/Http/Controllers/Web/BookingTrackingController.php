<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\V1\Queue\QueuePositionService;
use App\Support\PhoneNumber;
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

        $booking->load(['patient', 'clinic.doctor', 'clinic.specialty', 'visitType']);

        $clinic = $booking->clinic;
        // Parsed against the clinic's own country, not the platform
        // default: a Saudi clinic that typed its number the local way
        // parses to nothing as an Egyptian one, and the contact
        // buttons then disappear from the page entirely.
        $phone = $clinic->phone === null
            ? null
            : PhoneNumber::tryParse($clinic->phone, $clinic->country_code);

        // Written as someone who already has a booking, with their code, so
        // the clinic knows who is messaging without asking.
        $whatsappLink = $phone === null ? null : 'https://wa.me/'.ltrim((string) $phone, '+').'?text='.rawurlencode(
            __('booking.tracking.whatsapp_greeting', [
                'clinic' => $clinic->name,
                'code' => $booking->patient?->code,
            ]),
        );

        return view('tracking.show', [
            'booking' => $booking,
            'clinic' => $clinic,
            'doctor' => $clinic->doctor,
            'phone' => $phone,
            'whatsappLink' => $whatsappLink,
            'mapLink' => $clinic->mapLink(),
            'position' => $positions->for($booking),
            'refreshSeconds' => config('clinic.tracking.refresh_seconds'),
        ]);
    }
}
