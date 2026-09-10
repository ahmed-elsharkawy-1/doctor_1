<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Cloud API needs that the log driver never did.
 *
 * A template is addressed at Meta by its own name and language, which are not
 * ours to choose — `appointment_rating` is registered as `en_US` even though
 * every word of it is Arabic, and sending `ar` for it fails outright.
 *
 * The variables and the button suffix are stored on the message rather than
 * recomputed at send time, for the same reason `rendered_body` already is: a
 * message describes the booking as it stood when it was queued, and a booking
 * edited in between must not silently change what goes out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('message_templates', 'language_code')) {
                $table->string('language_code', 16)->default('ar')->after('provider_template_name');
            }
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_messages', 'variables')) {
                // The positional body parameters, in template order.
                $table->json('variables')->nullable()->after('rendered_body');
            }

            if (! Schema::hasColumn('outbound_messages', 'button_suffix')) {
                // The path appended to the template button's fixed base URL.
                $table->string('button_suffix')->nullable()->after('variables');
            }
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('message_templates', 'language_code')) {
                $table->dropColumn('language_code');
            }
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            foreach (['variables', 'button_suffix'] as $column) {
                if (Schema::hasColumn('outbound_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
