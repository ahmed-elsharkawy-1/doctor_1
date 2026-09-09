<?php

namespace App\Models;

use App\Enums\ReviewRating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient's verdict on one finished visit.
 */
class BookingReview extends Model
{
    protected $fillable = [
        'booking_id',
        'clinic_id',
        'doctor_id',
        'patient_id',
        'rating',
        'comment',
        'source',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => ReviewRating::class,
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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

    /** @param Builder<self> $query */
    public function scopeRated(Builder $query, ReviewRating $rating): void
    {
        $query->where('rating', $rating);
    }
}
