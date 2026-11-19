<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A patient who asked to stop WhatsApp messages.
 *
 * `whatsapp_opt_in_at` alone cannot say it: empty also means "never asked",
 * and every booking used to fill it in again, so a "stop" lasted until the
 * next visit. This stamp is what keeps a stop in place until the clinic
 * turns messages back on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dateTime('whatsapp_opted_out_at')->nullable()->after('whatsapp_opt_in_at');
            // `clinic` (from the app) or `patient` (replied «إيقاف»).
            $table->string('whatsapp_opt_out_source', 16)->nullable()->after('whatsapp_opted_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn(['whatsapp_opted_out_at', 'whatsapp_opt_out_source']);
        });
    }
};
