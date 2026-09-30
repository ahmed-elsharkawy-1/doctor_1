<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the provider accepted the code, if it ever did.
 *
 * A code whose send failed is kept — a timeout may still have delivered it —
 * but it must never be offered back to the patient as "we already sent you a
 * code". Only a row with this set is reused.
 *
 * Nullable, and left null on existing rows: nothing can say now whether those
 * were delivered, so none of them is reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verifications', function (Blueprint $table): void {
            $table->dateTime('sent_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('phone_verifications', function (Blueprint $table): void {
            $table->dropColumn('sent_at');
        });
    }
};
