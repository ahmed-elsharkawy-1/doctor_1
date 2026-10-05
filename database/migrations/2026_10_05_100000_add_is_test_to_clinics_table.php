<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the one clinic that exists for testing — "د. سارة أحمد", the same on
 * local, staging and production (see App\Support\TestClinic).
 *
 * It changes what a stranger sees, never how the clinic behaves: its public
 * page is labelled as a test clinic and kept out of search results. Bookings,
 * codes, messages and reports all work exactly as for a real clinic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false)->after('reports_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropColumn('is_test');
        });
    }
};
