<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient self-booking, per clinic.
 *
 * Off for every clinic, including the ones already running: opening a clinic's
 * day to a public form is the operator's decision, never a deploy's.
 *
 * The patient window is deliberately separate from `booking_window_days`. The
 * secretary can book to the edge of the clinic's window; patients are given
 * fewer days so she keeps room to place the people who phone her.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->boolean('self_booking_enabled')
                ->default(false)
                ->after('is_active');

            // Null means "no separate window" — the clinic's own window is
            // then used, clamped by Clinic::patientBookingWindowDays().
            $table->unsignedTinyInteger('patient_booking_window_days')
                ->nullable()
                ->after('booking_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropColumn(['self_booking_enabled', 'patient_booking_window_days']);
        });
    }
};
