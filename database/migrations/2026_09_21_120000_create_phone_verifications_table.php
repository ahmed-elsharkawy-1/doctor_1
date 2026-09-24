<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes proving somebody owns the phone they typed.
 *
 * The public booking page asks for the number the *patient* will be reached
 * on, which is not always the number of whoever is holding the browser — a
 * husband books for his wife from his own phone. Since patients are matched on
 * phone alone, getting this wrong would file her visit under his record for
 * ever. So the code goes to the number that will attend, and it is that number
 * the booking is saved against.
 *
 * Deliberately not `outbound_messages`: that table requires a patient, and a
 * code is sent before any patient record exists.
 *
 * Rows are kept only long enough to enforce the hourly caps, then swept by the
 * nightly command — a phone number is personal data and this table is the one
 * place it appears without a patient attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();

            // E.164, normalised against the clinic's own country before it
            // gets here, so the same phone always reads the same way.
            $table->string('phone', 32);

            // Hashed. A leaked database must not hand anybody a live code.
            $table->string('code_hash');

            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('verified_at')->nullable();

            // Only for rate limiting: one address must not be able to post
            // codes at a hundred different strangers.
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            // Both hourly caps and the newest-code lookup read this.
            $table->index(['clinic_id', 'phone', 'created_at']);
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
