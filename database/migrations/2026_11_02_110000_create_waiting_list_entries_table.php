<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The waiting list for a full day.
 *
 * On the clinic, a super admin's switch — off for new clinics and for every
 * clinic already running — and how many patients a day's list may hold. Null
 * means the platform default (clinic.waiting_list.max_per_day).
 *
 * An entry is a request, never a booking: it holds no slot and counts nowhere
 * a booking does. `booking_id` is set once the secretary books the patient,
 * and `messaged_at` once the end-of-day "no slot opened" message is queued,
 * so the closing command never sends it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            if (! Schema::hasColumn('clinics', 'waiting_list_enabled')) {
                $table->boolean('waiting_list_enabled')->default(false)->after('no_show_block_enabled');
            }

            if (! Schema::hasColumn('clinics', 'waiting_list_max_per_day')) {
                $table->unsignedSmallInteger('waiting_list_max_per_day')->nullable()->after('waiting_list_enabled');
            }
        });

        Schema::create('waiting_list_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('visit_date');
            $table->string('status', 20)->default('waiting');
            $table->string('source', 20);
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('messaged_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'visit_date', 'status']);
            $table->index(['patient_id', 'visit_date']);
        });

        Schema::table('outbound_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('outbound_messages', 'waiting_list_entry_id')) {
                $table->foreignId('waiting_list_entry_id')->nullable()->after('booking_id')
                    ->constrained('waiting_list_entries')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            if (Schema::hasColumn('outbound_messages', 'waiting_list_entry_id')) {
                $table->dropConstrainedForeignId('waiting_list_entry_id');
            }
        });

        Schema::dropIfExists('waiting_list_entries');

        Schema::table('clinics', function (Blueprint $table) {
            foreach (['waiting_list_enabled', 'waiting_list_max_per_day'] as $column) {
                if (Schema::hasColumn('clinics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
