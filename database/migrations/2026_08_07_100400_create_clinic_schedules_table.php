<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurring weekly pattern — one row per day of week, per clinic.
        Schema::create('clinic_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();

            // App\Enums\DayOfWeek — 0 = Saturday .. 6 = Friday.
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_open')->default(false);
            $table->timestamps();

            $table->unique(['clinic_id', 'day_of_week']);
        });

        // A day can have several separate periods (e.g. 13:00-15:00 and 17:00-21:00).
        Schema::create('clinic_schedule_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_schedule_id')->constrained()->cascadeOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['clinic_schedule_id', 'start_time']);
        });

        // One-off overrides on top of the weekly pattern.
        Schema::create('clinic_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_holidays');
        Schema::dropIfExists('clinic_schedule_periods');
        Schema::dropIfExists('clinic_schedules');
    }
};
