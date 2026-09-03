<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings databases migrated before the emergency-booking work in line with the
 * current `bookings` shape.
 *
 * The create_bookings migration was edited in place while an environment was
 * already deployed, so a database that ran it early has the old `is_overbooked`
 * flag and none of the columns that replaced it. Every step is guarded, so this
 * is a no-op on a database created from the current create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Replaced the boolean `force` override: normal vs emergency.
            if (! Schema::hasColumn('bookings', 'booking_kind')) {
                $table->string('booking_kind', 32)->default('normal')->after('cancel_reason');
            }

            // Inside the clinic vs on the way — drives the arrival lead time.
            if (! Schema::hasColumn('bookings', 'patient_location')) {
                $table->string('patient_location', 32)->nullable()->after('booking_kind');
            }

            // Stamped on arrival; the queue is ordered by it.
            if (! Schema::hasColumn('bookings', 'queue_entered_at')) {
                $table->dateTime('queue_entered_at')->nullable()->after('cancelled_at');
            }
        });

        // Emergency bookings are filtered per day on the queue screen.
        if (! $this->hasIndex('bookings_clinic_id_visit_date_booking_kind_index')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->index(['clinic_id', 'visit_date', 'booking_kind']);
            });
        }

        // Emergency bookings carry no slot, so both ends may be absent.
        foreach (['start_at', 'end_at'] as $column) {
            if (! $this->isNullable($column)) {
                Schema::table('bookings', function (Blueprint $table) use ($column): void {
                    $table->dateTime($column)->nullable()->change();
                });
            }
        }

        // The override flag this work removed.
        if (Schema::hasColumn('bookings', 'is_overbooked')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->dropColumn('is_overbooked');
            });
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('bookings', 'is_overbooked')) {
                $table->boolean('is_overbooked')->default(false)->after('notes');
            }
        });

        if ($this->hasIndex('bookings_clinic_id_visit_date_booking_kind_index')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->dropIndex(['clinic_id', 'visit_date', 'booking_kind']);
            });
        }

        Schema::table('bookings', function (Blueprint $table): void {
            foreach (['queue_entered_at', 'patient_location', 'booking_kind'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('bookings'))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }

    private function isNullable(string $column): bool
    {
        $found = collect(Schema::getColumns('bookings'))
            ->firstWhere('name', $column);

        return (bool) ($found['nullable'] ?? true);
    }
};
