<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this clinic's public booking page asks for a code by SMS. On for
 * every clinic, as today; the project-wide switch still overrides it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            if (! Schema::hasColumn('clinics', 'otp_required')) {
                $table->boolean('otp_required')->default(true)->after('self_booking_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            if (Schema::hasColumn('clinics', 'otp_required')) {
                $table->dropColumn('otp_required');
            }
        });
    }
};
