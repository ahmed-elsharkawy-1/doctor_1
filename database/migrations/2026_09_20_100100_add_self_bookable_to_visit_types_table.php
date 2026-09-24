<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which visit types a patient may choose for themselves.
 *
 * Defaults to true, for existing rows as well as new ones: a returning patient
 * is usually booking a follow-up, so restricting the public page to the
 * new-patient type would be wrong for most of the people using it.
 *
 * The flag exists so a clinic can take a long procedure back off the list —
 * `clinics.self_booking_enabled` is the master switch, this is the per-type one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_types', function (Blueprint $table): void {
            $table->boolean('is_self_bookable')
                ->default(true)
                ->after('is_new_patient_type');
        });
    }

    public function down(): void
    {
        Schema::table('visit_types', function (Blueprint $table): void {
            $table->dropColumn('is_self_bookable');
        });
    }
};
