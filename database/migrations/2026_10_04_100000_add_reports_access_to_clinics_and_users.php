<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The doctor's report: which clinics get it, and who may read it.
 *
 * Both off for everyone already here. A report carries a clinic's income and
 * its patients' names and phones, so who sees it is somebody's decision in the
 * panel, never a deploy's.
 *
 * `can_access_reports` stands in for real per-clinic roles until they exist.
 * Every clinic account keeps `role = clinic`, so the mobile app sees nothing
 * new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->boolean('reports_enabled')->default(false)->after('whatsapp_enabled');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_access_reports')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_access_reports');
        });

        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropColumn('reports_enabled');
        });
    }
};
