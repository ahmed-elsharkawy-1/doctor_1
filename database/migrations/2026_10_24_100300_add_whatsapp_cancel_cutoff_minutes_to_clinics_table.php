<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How close to the visit a patient may still cancel from the reminder.
 *
 * Inside this many minutes the Cancel button is refused and the patient is
 * asked to call, so a slot is never freed too late for anybody to take it.
 * 0 keeps Cancel open until the visit starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            if (! Schema::hasColumn('clinics', 'whatsapp_cancel_cutoff_minutes')) {
                $table->unsignedSmallInteger('whatsapp_cancel_cutoff_minutes')
                    ->default(config('clinic.defaults.whatsapp_cancel_cutoff_minutes', 60))
                    ->after('reminder_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            if (Schema::hasColumn('clinics', 'whatsapp_cancel_cutoff_minutes')) {
                $table->dropColumn('whatsapp_cancel_cutoff_minutes');
            }
        });
    }
};
