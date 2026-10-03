<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp, per clinic.
 *
 * On for every clinic, including the ones already running: until now every
 * clinic sent, and a deploy must not quietly stop that. Switching a clinic
 * off — while it pilots the app, say — is a super admin's decision in the
 * panel. See WhatsAppMessagingService for what "off" means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->boolean('whatsapp_enabled')
                ->default(true)
                ->after('self_booking_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_enabled');
        });
    }
};
