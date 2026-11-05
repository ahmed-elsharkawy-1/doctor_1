<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the patient's answer came from: her own WhatsApp reply, or the
 * secretary confirming it by hand after a phone call. `patient_response_by`
 * is the staff account that recorded a manual one.
 *
 * Every answer stored before this column existed came through WhatsApp,
 * because that was the only way to give one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('bookings', 'patient_response_source')) {
                $table->string('patient_response_source', 32)
                    ->nullable()
                    ->after('patient_responded_at');
            }

            if (! Schema::hasColumn('bookings', 'patient_response_by')) {
                $table->foreignId('patient_response_by')
                    ->nullable()
                    ->after('patient_response_source')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        DB::table('bookings')
            ->whereNotNull('patient_response')
            ->whereNull('patient_response_source')
            ->update(['patient_response_source' => 'whatsapp']);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (Schema::hasColumn('bookings', 'patient_response_by')) {
                $table->dropConstrainedForeignId('patient_response_by');
            }

            if (Schema::hasColumn('bookings', 'patient_response_source')) {
                $table->dropColumn('patient_response_source');
            }
        });
    }
};
