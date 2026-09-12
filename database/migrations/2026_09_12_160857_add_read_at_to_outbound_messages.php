<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta reports four things about a message: sent, delivered, read, failed.
 * We already had somewhere to put the first two; this is the third.
 *
 * Worth having because "delivered but never read" and "read" are different
 * conversations with a patient who says they were not told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_messages', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('outbound_messages', 'read_at')) {
                $table->dropColumn('read_at');
            }
        });
    }
};
