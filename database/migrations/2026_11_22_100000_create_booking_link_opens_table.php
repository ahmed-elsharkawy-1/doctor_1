<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first time each booking's tracking link was opened. One row per
 * booking, so reopening the page or its auto-refresh never counts twice.
 * Recorded from this release on; nothing earlier can be reconstructed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_link_opens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // The application's clock (Africa/Cairo), like created_at.
            $table->timestamp('opened_at');

            $table->index(['clinic_id', 'opened_at']);
        });

        Schema::create('booking_link_tracking_starts', function (Blueprint $table): void {
            $table->string('metric')->primary();
            $table->timestamp('started_at');
        });

        DB::table('booking_link_tracking_starts')->insert([
            'metric' => 'queue_link_open',
            'started_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_link_tracking_starts');
        Schema::dropIfExists('booking_link_opens');
    }
};
