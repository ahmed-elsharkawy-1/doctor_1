<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A super admin's switch, per clinic, for the expected time on the patient's
 * tracking page (and the secretary's queue search), under the platform
 * switch clinic.tracking.show_estimate.
 *
 * Off for new clinics and for every clinic already running: turning the
 * platform switch on changes nothing until a clinic is switched on too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            if (! Schema::hasColumn('clinics', 'tracking_estimate_enabled')) {
                $table->boolean('tracking_estimate_enabled')->default(false)->after('patient_arrival_lead_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            if (Schema::hasColumn('clinics', 'tracking_estimate_enabled')) {
                $table->dropColumn('tracking_estimate_enabled');
            }
        });
    }
};
