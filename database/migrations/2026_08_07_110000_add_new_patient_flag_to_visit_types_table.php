<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which visit type means "this is a new concern" — كشف in most clinics.
 *
 * The visit-type mismatch warning (SPEC §4.3) needs to know this, and it must
 * not be inferred from a hardcoded Arabic name: every clinic renames its own
 * visit types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_types', function (Blueprint $table) {
            $table->boolean('is_new_patient_type')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('visit_types', function (Blueprint $table) {
            $table->dropColumn('is_new_patient_type');
        });
    }
};
