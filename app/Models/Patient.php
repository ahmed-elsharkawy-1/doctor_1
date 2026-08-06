<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'code',
        'name',
        'phone',
    ];

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<Booking, $this> */
    public function completedVisits(): HasMany
    {
        return $this->bookings()->where('status', BookingStatus::DONE);
    }

    /**
     * The visit type of the most recent completed visit — drives the
     * visit-type mismatch warning (SPEC §6.3 of the PRD, §4.3 here).
     */
    public function lastCompletedVisit(): ?Booking
    {
        return $this->completedVisits()->latest('start_at')->first();
    }

    public function isReturning(): bool
    {
        return $this->completedVisits()->exists();
    }

    /** @param Builder<self> $query */
    public function scopeForClinic(Builder $query, int $clinicId): void
    {
        $query->where('clinic_id', $clinicId);
    }

    /**
     * Search by name or ID code — the two things the secretary types.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('name', 'like', '%'.$term.'%')
                ->orWhere('code', 'like', $term.'%');
        });
    }
}
