<?php

use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Settings\BootstrapController;
use App\Http\Controllers\Api\V1\Settings\CreateHolidayController;
use App\Http\Controllers\Api\V1\Settings\CreateVisitTypeController;
use App\Http\Controllers\Api\V1\Settings\DeleteHolidayController;
use App\Http\Controllers\Api\V1\Settings\GetScheduleController;
use App\Http\Controllers\Api\V1\Settings\HideVisitTypeController;
use App\Http\Controllers\Api\V1\Settings\ListHolidaysController;
use App\Http\Controllers\Api\V1\Settings\ListVisitTypesController;
use App\Http\Controllers\Api\V1\Settings\UpdateGeneralSettingsController;
use App\Http\Controllers\Api\V1\Settings\UpdateScheduleDayController;
use App\Http\Controllers\Api\V1\Settings\UpdateVisitTypeController;
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

Route::middleware(['auth:sanctum', 'clinic'])->group(function (): void {

    // One launch call: clinic config, visit types, the week, holidays.
    Route::get('bootstrap', BootstrapController::class)->name('api.v1.bootstrap');

    /*
    | Settings — editable by the secretary and the owner alike. The price
    | field is the one exception: it is filtered per-caller inside the
    | request and result classes, not by a separate route (SPEC §4.6).
    */
    Route::middleware('ability:settings.manage')->group(function (): void {

        Route::prefix('visit-types')->group(function (): void {
            Route::get('/', ListVisitTypesController::class)->name('api.v1.visit-types.index');
            Route::post('/', CreateVisitTypeController::class)->name('api.v1.visit-types.store');
            Route::put('{visitType}', UpdateVisitTypeController::class)
                ->whereNumber('visitType')
                ->name('api.v1.visit-types.update');
            // Hides rather than deletes — bookings reference this row forever.
            Route::delete('{visitType}', HideVisitTypeController::class)
                ->whereNumber('visitType')
                ->name('api.v1.visit-types.hide');
        });

        Route::prefix('schedule')->group(function (): void {
            Route::get('/', GetScheduleController::class)->name('api.v1.schedule.index');
            Route::put('{day}', UpdateScheduleDayController::class)
                ->whereNumber('day')
                ->name('api.v1.schedule.update');
        });

        Route::prefix('holidays')->group(function (): void {
            Route::get('/', ListHolidaysController::class)->name('api.v1.holidays.index');
            Route::post('/', CreateHolidayController::class)->name('api.v1.holidays.store');
            Route::delete('{holiday}', DeleteHolidayController::class)
                ->whereNumber('holiday')
                ->name('api.v1.holidays.destroy');
        });

        Route::put('settings/general', UpdateGeneralSettingsController::class)
            ->name('api.v1.settings.general');
    });

    /*
    | Phase 2+ endpoints mount here: booking-days, slots, patients, bookings,
    | queue, postpone.
    */
});
