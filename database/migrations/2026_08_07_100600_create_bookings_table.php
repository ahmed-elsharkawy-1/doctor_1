<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_type_id')->constrained()->restrictOnDelete();

            $table->date('visit_date');
            $table->dateTime('start_at');
            $table->dateTime('end_at');

            // Snapshots taken at creation — a later price or duration change must
            // never rewrite history (SPEC §3.3).
            $table->unsignedSmallInteger('duration_minutes');
            $table->decimal('price', 10, 2);

            $table->string('status', 32);
            $table->string('cancel_reason', 32)->nullable();

            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('called_in_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            // Call list worklist (SPEC §4.5).
            $table->dateTime('contacted_at')->nullable();
            $table->foreignId('rebooked_booking_id')->nullable()
                ->constrained('bookings')->nullOnDelete();

            $table->boolean('is_overbooked')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['clinic_id', 'visit_date', 'status']);
            $table->index(['doctor_id', 'start_at']);
            $table->index(['patient_id', 'start_at']);
            $table->index(['clinic_id', 'status', 'cancel_reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
