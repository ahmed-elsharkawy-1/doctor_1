<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A date on which a clinic sees every visit at one length of its own — the
 * doctor wants twenty minutes a patient for a few days instead of the usual
 * ten. Only that date changes: every other day keeps each visit type's own
 * length, so nothing has to be switched back once it has passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clinic_visit_length_days')) {
            return;
        }

        Schema::create('clinic_visit_length_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('minutes');
            $table->timestamps();

            $table->unique(['clinic_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_visit_length_days');
    }
};
