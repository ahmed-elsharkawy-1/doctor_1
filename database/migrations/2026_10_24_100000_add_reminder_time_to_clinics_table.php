<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time of day the clinic's single WhatsApp reminder goes out.
 *
 * Stored as the clinic's own wall clock, like every other time in this
 * application. `11:00` is the default for a clinic that has never touched
 * the setting, so a deploy does not silently stop reminding anybody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            if (! Schema::hasColumn('clinics', 'reminder_time')) {
                $table->string('reminder_time', 5)
                    ->default(config('clinic.defaults.reminder_time', '11:00'))
                    ->after('patient_arrival_lead_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            if (Schema::hasColumn('clinics', 'reminder_time')) {
                $table->dropColumn('reminder_time');
            }
        });
    }
};
