<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The first time a patient opened their booking's tracking link. Feeds the
 * "Queue Link Open Rate" statistic; see the migration for why one row.
 */
class BookingLinkOpen extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'clinic_id',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
        ];
    }

    /**
     * Records the open unless one is already recorded. insertOrIgnore leans on
     * the unique booking_id, so two requests racing cannot both write.
     */
    public static function recordFirst(Booking $booking): void
    {
        static::query()->insertOrIgnore([
            'booking_id' => $booking->id,
            'clinic_id' => $booking->clinic_id,
            'opened_at' => Carbon::now('UTC'),
        ]);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
