<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each report sent to a doctor's WhatsApp.
 *
 * Not `outbound_messages`: that table belongs to patients (`patient_id` is
 * required) and these go to clinic accounts.
 *
 * The unique key is the promise that a report goes out once: the morning job
 * and a "send now" from the panel can both run for the same period, and only
 * one of them sends unless the second is an explicit resend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 8);
            // A day, a week's Saturday, or a month's first day.
            $table->date('period_start');
            $table->string('status', 16)->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_type', 'period_start']);
            $table->index(['clinic_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
    }
};
