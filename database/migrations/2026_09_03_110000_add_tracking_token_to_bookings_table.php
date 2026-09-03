<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The secret behind the patient tracking link (SPEC v1.1 §7).
 *
 * Nullable so the column can be added to a live table without a default, then
 * backfilled, then made unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'tracking_token')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->string('tracking_token', 64)->nullable()->after('notes');
            });
        }

        // Existing bookings predate the page but may still be upcoming.
        Booking::whereNull('tracking_token')
            ->select(['id'])
            ->chunkById(500, function ($bookings): void {
                foreach ($bookings as $booking) {
                    Booking::whereKey($booking->id)->update([
                        'tracking_token' => Str::random(64),
                    ]);
                }
            });

        if (! $this->hasIndex('bookings_tracking_token_unique')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->unique('tracking_token');
            });
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (Schema::hasColumn('bookings', 'tracking_token')) {
                $table->dropUnique('bookings_tracking_token_unique');
                $table->dropColumn('tracking_token');
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('bookings'))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
