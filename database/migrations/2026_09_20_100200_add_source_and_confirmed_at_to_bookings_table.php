<?php

use App\Enums\BookingSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which door a booking came through.
 *
 * Deliberately NOT a seventh BookingStatus. `BookingStatus` is consumed by
 * exhaustive `match` expressions with no default arm (QueueService::sortKey,
 * BookingStatus::next), so a new case would throw on the clinic's own queue
 * screen. More importantly the two are different questions: a self-booking is
 * genuinely `booked` — it holds its slot from the moment it is made — and
 * "who created this" has nothing to do with where the patient is in the visit.
 *
 * This follows `booking_kind`, which is the same shape: a second dimension
 * beside the status rather than inside it.
 *
 * There is deliberately no "acknowledged" stamp beside it. A self-booking
 * needs no approval to become real, so a flag recording that somebody had
 * looked at it would gate nothing — it would only be an unread marker, and an
 * unread marker that goes stale the moment the booking is cancelled instead.
 * The badge on the card says where the booking came from for as long as the
 * booking exists, which is the durable version of the same signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('source', 32)
                ->default(BookingSource::CLINIC->value)
                ->after('patient_location');

            // For "show me only what patients booked themselves" — the filter
            // the queue and the mobile home screen will read.
            $table->index(['clinic_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['clinic_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
