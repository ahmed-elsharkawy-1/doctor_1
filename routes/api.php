<?php

use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Prefixed with /api/v1 (see bootstrap/app.php). Every route returns the
| standard response envelope — SPEC §6.
|
| The `clinic` middleware resolves the caller's clinic from their token and
| refuses the request if there isn't a usable one. No endpoint ever accepts
| clinic_id from the client.
|
*/

Route::prefix('auth')->group(function (): void {
    Route::post('login', LoginController::class)
        ->middleware('throttle:10,1')
        ->name('api.v1.auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::get('me', CurrentUserController::class)->name('api.v1.auth.me');
    });
});

/*
| Phase 1+ endpoints mount here, behind auth + clinic scoping:
|
| Route::middleware(['auth:sanctum', 'clinic'])->group(function (): void {
|     // settings, bookings, queue, patients
| });
*/
