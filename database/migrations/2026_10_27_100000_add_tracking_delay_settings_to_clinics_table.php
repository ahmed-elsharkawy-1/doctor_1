<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-clinic tuning for the delay notice on the patient's tracking page.
 * Empty means the platform default (config clinic.tracking.delay), the
 * convention every per-clinic override here follows — so adding them
 * changes nothing for a clinic nobody has tuned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            // Taken off the estimated lateness before it is shown.
            $table->unsignedSmallInteger('tracking_delay_reduction_minutes')->nullable()->after('patient_arrival_lead_minutes');
            // A shown delay must be more than this to show at all.
            $table->unsignedSmallInteger('tracking_delay_threshold_minutes')->nullable()->after('tracking_delay_reduction_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropColumn(['tracking_delay_reduction_minutes', 'tracking_delay_threshold_minutes']);
        });
    }
};
