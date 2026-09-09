<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the patient said about a finished visit.
 *
 * One row per booking, enforced by the database rather than by a hidden field
 * on a form the patient could submit twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reviews', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();

            // Reachable through the booking, but every screen groups by one of
            // these, so they are stored and indexed rather than joined for.
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->string('rating', 20);
            $table->text('comment')->nullable();

            // How the answer reached us. Costs nothing now, and means moving to
            // a WhatsApp Flow later does not invalidate the rows already here.
            $table->string('source', 20)->default('review_page');

            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['clinic_id', 'rating']);
            $table->index(['doctor_id', 'rating']);
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reviews');
    }
};
