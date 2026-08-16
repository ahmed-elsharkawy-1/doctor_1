<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'doctor_id',
        'patient_id',
        'visit_type_id',
        'visit_date',
        'start_at',
        'end_at',
        'duration_minutes',
        'price',
        'status',
        'cancel_reason',
        'arrived_at',
        'called_in_at',
        'completed_at',
        'cancelled_at',
        'contacted_at',
        'rebooked_booking_id',
        'is_overbooked',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            // Stored as a bare date. Without the explicit format it is written
            // as "2026-08-11 00:00:00" on SQLite but as a pure DATE on MySQL,
            // so a single-day whereBetween would match on one driver and not
            // the other.
            'visit_date' => 'date:Y-m-d',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'status' => BookingStatus::class,
            'cancel_reason' => CancelReason::class,
            'arrived_at' => 'datetime',
            'called_in_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'contacted_at' => 'datetime',
            'is_overbooked' => 'boolean',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
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

    /** @return BelongsTo<self, $this> */
    public function rebookedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rebooked_booking_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** @param Builder<self> $query */
    public function scopeForClinic(Builder $query, int $clinicId): void
    {
        $query->where('clinic_id', $clinicId);
    }

    /** @param Builder<self> $query */
    public function scopeOnDate(Builder $query, mixed $date): void
    {
        $query->whereDate('visit_date', $date);
    }

    /**
     * Bookings that hold a time slot. Cancelled and no-show bookings free
     * theirs (SPEC §5.1).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOccupyingSlot(Builder $query): void
    {
        $query->whereIn('status', BookingStatus::occupyingSlot());
    }

    /**
     * Still in play today — the postpone candidates (SPEC §4.5).
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', BookingStatus::pending());
    }

    /** @param Builder<self> $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', BookingStatus::DONE);
    }

    /**
     * Overlaps a proposed [start, end) window.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, mixed $startAt, mixed $endAt): void
    {
        $query->where('start_at', '<', $endAt)->where('end_at', '>', $startAt);
    }

    /**
     * Awaiting a new appointment after a postponement (SPEC §4.5).
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingRebooking(Builder $query): void
    {
        $query->where('status', BookingStatus::CANCELLED)
            ->where('cancel_reason', CancelReason::EMERGENCY)
            ->whereNull('rebooked_booking_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled();
    }
}
