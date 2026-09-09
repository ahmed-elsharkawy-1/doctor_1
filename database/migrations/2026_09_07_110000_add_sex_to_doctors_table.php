<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Picks the stock avatar for a doctor with no photo yet. Nullable, because
 * doctors created before this have no answer and fall back to their initial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table): void {
            if (! Schema::hasColumn('doctors', 'sex')) {
                $table->string('sex', 10)->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table): void {
            $table->dropColumn('sex');
        });
    }
};
