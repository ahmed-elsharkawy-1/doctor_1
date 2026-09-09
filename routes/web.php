<?php

use App\Http\Controllers\Docs\ApiReferenceController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\BookingReviewController;
use App\Http\Controllers\Web\BookingTrackingController;
use App\Http\Controllers\Web\DoctorLandingController;
use App\Http\Middleware\EnsureClinicSession;
use App\Livewire\App\NewBooking;
use App\Livewire\App\Queue;
use Illuminate\Support\Facades\Route;

/*
| The platform root. Clinics live at `/{slug}`, so this is only a signpost
| for staff who typed the bare domain.
*/
Route::get('/', function () {
    app()->setLocale(config('clinic.api.default_locale'));

    return view('welcome');
})->name('root');

/*
| The patient's booking-tracking page. Unauthenticated by design: the clinic
| sends the link over WhatsApp and the token in it is the whole secret, so
| the path stays short enough to read in a message.
*/
Route::get(config('clinic.tracking.path').'/{booking:tracking_token}', BookingTrackingController::class)
    ->name('booking.track');

// Links already delivered to patients used the older, shorter path. Their
// WhatsApp history cannot be rewritten, so those keep resolving for ever.
foreach (config('clinic.tracking.legacy_paths', []) as $legacyPath) {
    Route::get($legacyPath.'/{booking:tracking_token}', BookingTrackingController::class);
}

/*
| The patient's review page, opened from the visit-completed message. Same
| token as the tracking page: it is already this patient's key to this visit.
*/
Route::get(config('clinic.review.path').'/{booking:tracking_token}', [BookingReviewController::class, 'show'])
    ->name('booking.review');

Route::post(config('clinic.review.path').'/{booking:tracking_token}', [BookingReviewController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('booking.review.store');

/*
| The clinic web app — the screens the doctor and the assistant work from.
|
| Session auth on the existing `web` guard. The screens are Livewire
| components that call the same services as the mobile API; no booking or
| queue rule is restated here.
*/
Route::prefix('app')->name('app.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('login', [LoginController::class, 'show'])->name('login');
        Route::post('login', [LoginController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('login.store');
    });

    // EnsureClinicSession does the guest redirect itself, so the framework's
    // `auth` middleware is not used here — it redirects to a route named
    // `login`, which this app deliberately does not have.
    Route::middleware(EnsureClinicSession::class)->group(function (): void {
        Route::get('/', Queue::class)->name('queue');
        Route::get('bookings/new', NewBooking::class)->name('bookings.new');
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    });
});

/*
| Browsable API reference, rendered from docs/api/v1/openapi.yaml.
|
| Disabled in production unless API_DOCS_ENABLED is set — the spec is not
| secret, but publishing a full map of the API should be deliberate.
*/
Route::prefix(config('clinic.docs.path'))->group(function (): void {
    Route::get('/', [ApiReferenceController::class, 'page'])->name('docs.api');
    Route::get('handoff', [ApiReferenceController::class, 'handoff'])->name('docs.api.handoff');
    Route::get('design-map', [ApiReferenceController::class, 'designMap'])->name('docs.api.design-map');
    Route::get('openapi.json', [ApiReferenceController::class, 'document'])->name('docs.api.spec');
});

/*
| The public doctor landing page.
|
| Registered last on purpose: it matches a single path segment, so every
| route above wins the match and a clinic can never take a path the app
| already owns. `clinic.landing.reserved` stops one being created anyway.
*/
Route::get('/{slug}', DoctorLandingController::class)
    ->where('slug', '[a-z0-9][a-z0-9-]*')
    ->name('landing');
