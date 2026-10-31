<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A super admin's switch for each reminder, and how long before the morning
 * reminder a booking must have been made to get it.
 *
 * Both switches default to off, for new clinics and for every clinic already
 * running: a clinic gets a reminder when somebody decides it does, never
 * because a deploy turned it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            if (! Schema::hasColumn('clinics', 'daily_reminder_enabled')) {
                $table->boolean('daily_reminder_enabled')->default(false)->after('reminder_time');
            }

            if (! Schema::hasColumn('clinics', 'daily_reminder_min_hours')) {
                $table->unsignedSmallInteger('daily_reminder_min_hours')
                    ->default(config('clinic.defaults.daily_reminder_min_hours', 12))
                    ->after('daily_reminder_enabled');
            }

            if (! Schema::hasColumn('clinics', 'pre_visit_reminder_enabled')) {
                $table->boolean('pre_visit_reminder_enabled')->default(false)->after('reminder_position');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            foreach (['daily_reminder_enabled', 'daily_reminder_min_hours', 'pre_visit_reminder_enabled'] as $column) {
                if (Schema::hasColumn('clinics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
