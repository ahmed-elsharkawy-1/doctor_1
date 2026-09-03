<?php

use App\Http\Controllers\Docs\ApiReferenceController;
use App\Http\Controllers\Web\BookingTrackingController;
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
