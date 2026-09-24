<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A slot somebody has selected but not yet booked.
 *
 * Three doors now compete for the same times — the mobile app, the clinic web
 * app and the patient's own page — and the day lock already stops two of them
 * *saving* the same slot. What it cannot do is stop two people spending a
 * minute each filling in a form only one of them can finish. That is what a
 * hold is for: it is claimed the moment a time is tapped, so everyone else
 * sees the slot go immediately.
 *
 * Holds block everyone alike. There is no override — staff and patients queue
 * behind each other in exactly the same way, because one rule is easier to
 * trust than two, and the worst case is a five-minute wait for a slot somebody
 * is actively booking.
 *
 * Nothing sweeps this table for correctness: `expires_at` is a query filter,
 * so an expired hold stops counting the moment it lapses whether or not any
 * cleanup has run. The nightly purge is housekeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_type_id')->constrained()->restrictOnDelete();

            $table->date('visit_date');
            $table->dateTime('start_at');
            // Stored rather than derived: the hold has to keep blocking the
            // window it claimed even if the visit type's length changes while
            // somebody is mid-booking.
            $table->dateTime('end_at');

            // The holder's identity, and the only thing that lets a holder
            // book the slot it is sitting on. Unguessable by design — a
            // browser session id for a patient, a per-screen token for staff.
            $table->string('token', 64)->unique();

            // Mirrors BookingSource. Kept for support and for reading the
            // logs; it deliberately grants nobody any privilege.
            $table->string('source', 32);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('expires_at');
            $table->timestamps();

            // How availability reads it: this clinic, this day, still live.
            $table->index(['clinic_id', 'visit_date', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_holds');
    }
};
