<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text follow-ups and the one retry a reminder gets.
 *
 * `type` is `template` for everything that existed before this migration, and
 * `text` for the in-session message sent after a patient taps a reminder
 * button — that one is not a template and must not be looked up as one.
 *
 * `attempts` records how many times the provider was actually called. A
 * reminder is allowed two; any other message keeps the queue's own retry
 * behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_messages', 'type')) {
                $table->string('type', 16)->default('template')->after('template_key');
            }

            if (! Schema::hasColumn('outbound_messages', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            foreach (['attempts', 'type'] as $column) {
                if (Schema::hasColumn('outbound_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
