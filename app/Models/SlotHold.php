<?php

namespace App\Models;

use App\Enums\BookingSource;
use Database\Factories\SlotHoldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A slot somebody is part way through booking.
 *
 * Advisory, not authoritative: the day lock and `guardSlot()` are still what
 * decide whether a booking may be written. A hold only stops other people
 * wasting their time on a slot that is about to go.
 */
class SlotHold extends Model
{
    /** @use HasFactory<SlotHoldFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'visit_type_id',
        'visit_date',
        'start_at',
        'end_at',
        'token',
        'source',
        'created_by',
        'expires_at',
    ];

    /**
     * The token is what lets its holder book the slot it is blocking, so it is
     * never serialised by accident.
     */
    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            // Matching Booking: a bare date on both drivers.
            'visit_date' => 'date:Y-m-d',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'expires_at' => 'datetime',
            'source' => BookingSource::class,
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return BelongsTo<VisitType, $this> */
    public function visitType(): BelongsTo
    {
        return $this->belongsTo(VisitType::class);
    }

    /**
     * Still blocking the slot.
     *
     * Expiry is answered by this filter and never by a cleanup job, so a
     * lapsed hold stops counting the moment it lapses — even if nothing has
     * swept the table for a week.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query, ?Carbon $now = null): void
    {
        $query->where('expires_at', '>', $now ?? Carbon::now());
    }

    /** @param Builder<self> $query */
    public function scopeOnDate(Builder $query, mixed $date): void
    {
        $query->whereDate('visit_date', $date);
    }

    /**
     * Everyone else's holds. A holder is never blocked by its own.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotHeldBy(Builder $query, ?string $token): void
    {
        $query->when($token !== null, fn (Builder $q) => $q->where('token', '!=', $token));
    }

    public function hasExpired(?Carbon $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }
}
