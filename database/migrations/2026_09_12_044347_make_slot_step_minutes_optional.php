<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A slot grid follows the visit, not the clock.
 *
 * `slot_step_minutes` set how often a start time was offered, independently of
 * how long the visit actually was — so a 20-minute consultation on a 10-minute
 * step was offered at 13:00, 13:10, 13:20, each overlapping the last. The
 * bookings were never wrong (taking one removed the others) but the list read
 * as broken, and nobody could say what the number meant.
 *
 * Null now means "follow each visit type's own duration", which is how
 * appointment systems are normally built. A clinic that genuinely wants
 * rolling start times can still set a number and get exactly the old
 * behaviour back, per clinic, with no deploy.
 *
 * Existing clinics are emptied: every one of them holds the seeded default of
 * 10, which nobody chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->unsignedTinyInteger('slot_step_minutes')->nullable()->change();
        });

        DB::table('clinics')->update(['slot_step_minutes' => null]);
    }

    public function down(): void
    {
        DB::table('clinics')
            ->whereNull('slot_step_minutes')
            ->update(['slot_step_minutes' => config('clinic.defaults.slot_step_minutes') ?? 10]);

        Schema::table('clinics', function (Blueprint $table): void {
            $table->unsignedTinyInteger('slot_step_minutes')->nullable(false)->change();
        });
    }
};
