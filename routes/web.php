<?php

use App\Http\Controllers\Docs\ApiReferenceController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\BookingTrackingController;
use App\Http\Middleware\EnsureClinicSession;
use App\Livewire\App\Queue;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| The patient's booking-tracking page. Unauthenticated by design: the clinic
| sends the link over WhatsApp and the token in it is the whole secret, so
| the path stays short enough to read in a message.
*/
Route::get(config('clinic.tracking.path').'/{booking:tracking_token}', BookingTrackingController::class)
    ->name('booking.track');

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
