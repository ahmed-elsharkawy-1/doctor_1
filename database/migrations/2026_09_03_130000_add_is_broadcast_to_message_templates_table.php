<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates the templates a clinic may send to a whole day from the ones the
 * system sends for a single booking.
 *
 * Without it, `booking_confirmed` would appear on the broadcast screen and a
 * secretary could send every patient on a day someone else's confirmation.
 * Defaults to true, so the three existing templates keep behaving as they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_templates', 'is_broadcast')) {
            Schema::table('message_templates', function (Blueprint $table): void {
                $table->boolean('is_broadcast')->default(true)->after('category');
            });
        }
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('message_templates', 'is_broadcast')) {
                $table->dropColumn('is_broadcast');
            }
        });
    }
};
