<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use links that open the reports page inside the mobile app.
 *
 * Only a hash of each link is kept, so a link read from a log or a screenshot
 * cannot be matched back. A link lives two minutes and opens once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_web_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_web_links');
    }
};
