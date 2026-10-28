<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the patient said in answer to the reminder.
 *
 * Deliberately not a seventh booking status. The six-status model describes
 * where the visit is; this describes one reply from the patient and is only
 * meaningful beside `booked`. A cancellation still becomes `cancelled`, so
 * "ألغت المريضة" does not change how the rest of the system reads a visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('bookings', 'patient_response')) {
                $table->string('patient_response', 32)
                    ->nullable()
                    ->after('status');
            }

            if (! Schema::hasColumn('bookings', 'patient_responded_at')) {
                $table->dateTime('patient_responded_at')
                    ->nullable()
                    ->after('patient_response');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            foreach (['patient_responded_at', 'patient_response'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
