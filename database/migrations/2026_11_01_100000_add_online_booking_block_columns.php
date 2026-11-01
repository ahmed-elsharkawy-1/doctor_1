<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeping a patient who keeps not turning up off the public booking page.
 *
 * The clinic switch defaults to off, for new clinics and for every clinic
 * already running: a clinic gets the rule when somebody decides it does.
 *
 * On the patient, `online_booking_allowed_at` is when the clinic last lifted a
 * block — no-shows marked before it are no longer held against them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            if (! Schema::hasColumn('clinics', 'no_show_block_enabled')) {
                $table->boolean('no_show_block_enabled')->default(false)->after('self_booking_enabled');
            }
        });

        Schema::table('patients', function (Blueprint $table) {
            if (! Schema::hasColumn('patients', 'online_booking_blocked_at')) {
                $table->timestamp('online_booking_blocked_at')->nullable()->after('whatsapp_opt_in_at');
            }

            if (! Schema::hasColumn('patients', 'online_booking_block_reason')) {
                $table->string('online_booking_block_reason', 20)->nullable()->after('online_booking_blocked_at');
            }

            if (! Schema::hasColumn('patients', 'online_booking_allowed_at')) {
                $table->timestamp('online_booking_allowed_at')->nullable()->after('online_booking_block_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            foreach (['online_booking_blocked_at', 'online_booking_block_reason', 'online_booking_allowed_at'] as $column) {
                if (Schema::hasColumn('patients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('clinics', function (Blueprint $table) {
            if (Schema::hasColumn('clinics', 'no_show_block_enabled')) {
                $table->dropColumn('no_show_block_enabled');
            }
        });
    }
};
