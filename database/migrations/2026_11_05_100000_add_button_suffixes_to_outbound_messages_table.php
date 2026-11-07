<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A template can now carry two dynamic URL buttons: the rating message has
 * "rate your visit" and "book a new appointment", both with their own path.
 *
 * `button_suffix` keeps the first button's path, as before, so every existing
 * row, the admin's messages page and anything queued across the deploy read
 * the same. `button_suffixes` is filled only when there is more than one, and
 * then lists them all in button order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_messages', 'button_suffixes')) {
                $table->json('button_suffixes')->nullable()->after('button_suffix');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('outbound_messages', 'button_suffixes')) {
                $table->dropColumn('button_suffixes');
            }
        });
    }
};
