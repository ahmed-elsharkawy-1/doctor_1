<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Times when verification codes stopped reaching patients.
 *
 * The SMS provider cannot be trusted to say so: on 17 November 2026 its
 * portal showed "Delivered" for a whole day of codes nobody received. What it
 * cannot hide is that nobody typed them, and that is what opens a row here.
 * A row stays open until a code is typed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_alarms', function (Blueprint $table): void {
            $table->id();

            // The first code nobody typed, not when it was noticed.
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable()->index();

            // At the time it was noticed: codes asked for, different numbers,
            // and how many of them the provider refused outright.
            $table->unsignedInteger('codes');
            $table->unsignedInteger('phones');
            $table->unsignedInteger('refused');

            // Codes per clinic and when a code was last typed.
            $table->json('details')->nullable();

            // Each channel on its own, so one failing is retried without
            // repeating the other.
            $table->dateTime('emailed_at')->nullable();
            $table->dateTime('whatsapped_at')->nullable();
            $table->dateTime('recovery_sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_alarms');
    }
};
