<?php

namespace App\Models;

use App\Enums\DoctorSex;
use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Doctor extends Model
{
    /** @use HasFactory<DoctorFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'name',
        'title',
        'sex',
        'bio',
        'photo_path',
        'phone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sex' => DoctorSex::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * The مجالات العلاج list on the public page, in the operator's order.
     *
     * @return HasMany<TreatmentArea, $this>
     */
    public function treatmentAreas(): HasMany
    {
        return $this->hasMany(TreatmentArea::class)->orderBy('sort_order');
    }

    /**
     * The uploaded portrait, or null.
     */
    public function photoUrl(): ?string
    {
        if ($this->photo_path === null || $this->photo_path === '') {
            return null;
        }

        return Storage::disk(config('clinic.public.disk'))->url($this->photo_path);
    }

    /**
     * What the public page actually shows: the portrait if there is one, else
     * the stock avatar for the doctor's recorded sex. Null only when neither
     * is known, and the page then falls back to an initial.
     */
    public function avatarUrl(): ?string
    {
        return $this->photoUrl() ?? $this->sex?->avatarUrl();
    }

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

    /** @return HasMany<User, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
