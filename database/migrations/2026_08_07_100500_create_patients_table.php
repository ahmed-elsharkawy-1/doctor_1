<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();

            // Patients belong to a clinic — not shared across clinics (SPEC decision #4).
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();

            // Auto-generated and immutable once assigned (SPEC §5.3).
            $table->string('code', 32)->nullable();
            $table->string('name');

            // Stored E.164, displayed nationally (SPEC §5.2).
            $table->string('phone', 20);
            $table->unsignedSmallInteger('age')->nullable();
            $table->timestamp('whatsapp_opt_in_at')->nullable();

            $table->timestamps();

            $table->unique(['clinic_id', 'phone']);
            $table->unique(['clinic_id', 'code']);
            $table->index(['clinic_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
