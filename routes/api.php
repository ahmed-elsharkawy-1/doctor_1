<?php

use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Booking\BookingCalendarController;
use App\Http\Controllers\Api\V1\Booking\CreateBookingController;
use App\Http\Controllers\Api\V1\Booking\PatientLookupController;
use App\Http\Controllers\Api\V1\Booking\ShowBookingController;
use App\Http\Controllers\Api\V1\Booking\SlotsController;
use App\Http\Controllers\Api\V1\Booking\UpdateBookingController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\Messaging\CreateBroadcastController;
use App\Http\Controllers\Api\V1\Messaging\ListMessageTemplatesController;
use App\Http\Controllers\Api\V1\Messaging\SendBookingMessageController;
use App\Http\Controllers\Api\V1\Patients\SearchPatientsController;
use App\Http\Controllers\Api\V1\Patients\ShowPatientController;
use App\Http\Controllers\Api\V1\Queue\CancelBookingController;
use App\Http\Controllers\Api\V1\Queue\MarkContactedController;
use App\Http\Controllers\Api\V1\Queue\PostponeCandidatesController;
use App\Http\Controllers\Api\V1\Queue\PostponeController;
use App\Http\Controllers\Api\V1\Queue\RebookingListController;
use App\Http\Controllers\Api\V1\Queue\UpdateBookingStatusController;
use App\Http\Controllers\Api\V1\Reports\RetentionReportController;
use App\Http\Controllers\Api\V1\Reports\RevenueReportController;
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
    Route::get('home', HomeController::class)->name('api.v1.home');

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
    | Booking — the New Booking screen and the day strip.
    */
    Route::middleware('ability:bookings.manage')->group(function (): void {

        Route::get('bookings/calendar', BookingCalendarController::class)->name('api.v1.bookings.calendar');
        Route::get('slots', SlotsController::class)->name('api.v1.slots');

        // Recognises a returning patient and flags a visit-type mismatch
        // while the secretary is still filling the form.
        Route::post('patients/lookup', PatientLookupController::class)->name('api.v1.patients.lookup');

        Route::prefix('bookings')->group(function (): void {
            Route::post('/', CreateBookingController::class)->name('api.v1.bookings.store');
            Route::get('{booking}', ShowBookingController::class)
                ->whereNumber('booking')
                ->name('api.v1.bookings.show');
            Route::put('{booking}', UpdateBookingController::class)
                ->whereNumber('booking')
                ->name('api.v1.bookings.update');
            Route::post('{booking}/message', SendBookingMessageController::class)
                ->whereNumber('booking')
                ->name('api.v1.bookings.message');
        });

        Route::get('message-templates', ListMessageTemplatesController::class)
            ->name('api.v1.message-templates.index');
        Route::post('broadcasts', CreateBroadcastController::class)->name('api.v1.broadcasts.store');
    });

    /*
    | Booking status and rebooking worklists — SPEC §4.2, §4.5.
    |
    | Calendar cards are ordered by appointment time. Every transition is sent
    | explicitly because the app confirms each one with its own dialog.
    */
    Route::middleware('ability:queue.manage')->group(function (): void {

        Route::prefix('bookings/{booking}')->whereNumber('booking')->group(function (): void {
            Route::post('status', UpdateBookingStatusController::class)->name('api.v1.bookings.status');
            Route::post('cancel', CancelBookingController::class)->name('api.v1.bookings.cancel');
            // "تم الاتصال" on the call list.
            Route::post('contacted', MarkContactedController::class)->name('api.v1.bookings.contacted');
        });

        // Postpone today: cancels the selected bookings, freeing their slots,
        // and hands back the call list. Broadcast messaging lives under the
        // template endpoints above.
        Route::get('postpone/candidates', PostponeCandidatesController::class)
            ->name('api.v1.postpone.candidates');
        Route::post('postpone', PostponeController::class)->name('api.v1.postpone');

        Route::get('rebooking-list', RebookingListController::class)->name('api.v1.rebooking-list');
    });

    /*
    | Patient search and visit history (SPEC 4.4).
    |
    | Phones are masked in search results and shown in full on a patient's
    | own page, which is where the call action lives.
    */
    Route::middleware('ability:patients.view')->group(function (): void {
        Route::get('patients', SearchPatientsController::class)->name('api.v1.patients.index');
        Route::get('patients/{patient}', ShowPatientController::class)
            ->whereNumber('patient')
            ->name('api.v1.patients.show');
    });

    /*
    | Reports (SPEC 5.5, 5.6) - owner only.
    |
    | The doctor runs the clinic entirely from the app, panel included: there
    | is no web dashboard for a clinic, only for the platform operator.
    */
    Route::middleware('ability:reports.view')->prefix('reports')->group(function (): void {
        Route::get('revenue', RevenueReportController::class)->name('api.v1.reports.revenue');
        Route::get('retention', RetentionReportController::class)->name('api.v1.reports.retention');
    });
});
