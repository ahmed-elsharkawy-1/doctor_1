<?php

use App\Http\Controllers\Docs\ApiReferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
