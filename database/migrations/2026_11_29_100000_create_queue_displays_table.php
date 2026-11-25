<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The waiting-room TVs (ELA-19). Each shows one branch's queue for today, at
 * an unguessable address: the token is the whole of its access, like a
 * patient's tracking link, and can be replaced if it leaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_displays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('token', 64)->unique();
            // When a TV last asked for its board: whether it is on at all.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_displays');
    }
};
