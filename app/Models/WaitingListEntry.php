<?php

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\WaitingListStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient asking for a day that is already full.
 *
 * Not a booking: it holds no slot, and nothing about it is promised. If a
 * slot opens the secretary calls the patient and books it — the app never
 * offers one by itself. See WaitingListService.
 */
class WaitingListEntry extends Model
{
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'visit_type_id',
        'visit_date',
        'status',
        'source',
        'booking_id',
        'created_by',
        'left_at',
        'messaged_at',
    ];

    protected function casts(): array
    {
        return [
            // Matching Booking: a bare date on both drivers.
            'visit_date' => 'date:Y-m-d',
            'status' => WaitingListStatus::class,
            'source' => BookingSource::class,
            'left_at' => 'datetime',
            'messaged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<VisitType, $this> */
    public function visitType(): BelongsTo
    {
        return $this->belongsTo(VisitType::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isWaiting(): bool
    {
        return $this->status === WaitingListStatus::WAITING;
    }

    /** @param Builder<self> $query */
    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', WaitingListStatus::WAITING);
    }

    /** @param Builder<self> $query */
    public function scopeOnDate(Builder $query, string $date): void
    {
        $query->whereDate('visit_date', $date);
    }
}
