<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many slot lengths before the visit the pre-visit reminder goes out:
 * position × the booking's own duration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clinics', 'reminder_position')) {
            Schema::table('clinics', function (Blueprint $table) {
                $table->unsignedSmallInteger('reminder_position')
                    ->default(config('clinic.defaults.reminder_position', 1))
                    ->after('reminder_time');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clinics', 'reminder_position')) {
            Schema::table('clinics', function (Blueprint $table) {
                $table->dropColumn('reminder_position');
            });
        }
    }
};
