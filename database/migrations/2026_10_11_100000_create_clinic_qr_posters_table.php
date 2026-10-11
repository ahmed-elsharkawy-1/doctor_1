<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's adjustments to a clinic's QR poster.
 *
 * The poster itself is never stored: it is drawn from the clinic and its
 * doctor every time it is opened, so it exists the moment the clinic does and
 * follows every change to the doctor's name or title. This row only holds what
 * the operator changed to make it fit — every column null means "use the
 * default", and a clinic nobody has adjusted has no row at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_qr_posters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            // Line breaks the operator typed are kept: they choose where the
            // specialty line wraps.
            $table->string('title', 400)->nullable();
            $table->string('call_to_action', 200)->nullable();
            $table->unsignedSmallInteger('name_size')->nullable();
            $table->unsignedSmallInteger('title_size')->nullable();
            $table->unsignedSmallInteger('qr_size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_qr_posters');
    }
};
