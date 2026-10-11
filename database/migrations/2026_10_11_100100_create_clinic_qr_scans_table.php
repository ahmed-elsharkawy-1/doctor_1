<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many people opened a clinic's page from its QR poster, per day.
 *
 * A counter, not a log: nothing about the visitor is kept, so there is nothing
 * here to protect and nothing to clean up. One row per clinic per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_qr_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // The clinic's own calendar day, not the server's.
            $table->date('scan_date');
            $table->unsignedInteger('scans')->default(0);
            $table->timestamps();

            $table->unique(['clinic_id', 'scan_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_qr_scans');
    }
};
