<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two repeating blocks on the public page: مجالات العلاج under the doctor,
 * and صور العيادة for the clinic.
 *
 * Both are ordered lists the operator curates, so each carries its own
 * `sort_order` rather than relying on insertion order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('treatment_areas')) {
            Schema::create('treatment_areas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();

                $table->string('title');
                $table->string('description')->nullable();
                // A key from config('clinic.public.icons'), not markup — the
                // page renders its own SVG so nothing user-entered is trusted.
                $table->string('icon', 40)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);

                $table->timestamps();

                $table->index(['doctor_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('clinic_photos')) {
            Schema::create('clinic_photos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();

                $table->string('path');
                $table->string('caption')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);

                $table->timestamps();

                $table->index(['clinic_id', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_photos');
        Schema::dropIfExists('treatment_areas');
    }
};
