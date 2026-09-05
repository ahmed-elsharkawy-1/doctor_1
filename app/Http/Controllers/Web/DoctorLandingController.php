<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * The clinic's public page — the entrance to the whole funnel.
 *
 * Read-only, unauthenticated, and indexable: this is the one page in the
 * system that search engines are meant to find. It carries no booking form.
 * A patient asks on WhatsApp and the clinic takes the booking, which is the
 * flow the clinic already runs (SPEC v1.2).
 */
class DoctorLandingController extends Controller
{
    public function __invoke(string $slug): View
    {
        App::setLocale(config('clinic.api.default_locale'));

        $clinic = Clinic::query()
            ->with([
                'specialty',
                'doctor',
                'schedules.periods',
                // Hidden visit types are hidden from patients too.
                'visitTypes' => fn ($query) => $query->active(),
            ])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $phone = $clinic->phone === null ? null : PhoneNumber::tryParse($clinic->phone);

        return view('landing.show', [
            'clinic' => $clinic,
            'doctor' => $clinic->doctor,
            'phone' => $phone,
            // wa.me wants the international number with no punctuation.
            'whatsapp' => $phone === null ? null : ltrim((string) $phone, '+'),
            'openDays' => $clinic->schedules->where('is_open', true),
        ]);
    }
}
