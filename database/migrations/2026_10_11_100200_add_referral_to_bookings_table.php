<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What brought the patient to the booking page — today only the clinic's QR
 * poster.
 *
 * Its own column rather than another BookingSource case: `source` says who
 * made the booking (staff or the patient), this says how the patient found
 * us. A self-booking from the poster is still `patient_web`.
 *
 * Null for everything that did not arrive through something we can name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('referral', 32)->nullable()->after('source');

            $table->index(['clinic_id', 'referral']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['clinic_id', 'referral']);
            $table->dropColumn('referral');
        });
    }
};
