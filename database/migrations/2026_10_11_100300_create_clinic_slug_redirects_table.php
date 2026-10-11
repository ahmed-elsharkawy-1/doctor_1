<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every address a clinic's page used to live at.
 *
 * A slug is printed on posters and business cards, so changing it must never
 * break one: the old address keeps redirecting to the clinic for good. Unique
 * across all clinics — an address that still leads somewhere cannot be handed
 * to anyone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_slug_redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 120)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_slug_redirects');
    }
};
