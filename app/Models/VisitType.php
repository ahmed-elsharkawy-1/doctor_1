<?php

namespace App\Models;

use Database\Factories\VisitTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitType extends Model
{
    /** @use HasFactory<VisitTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'name',
        'description',
        'duration_minutes',
        'price',
        'is_active',
        'is_new_patient_type',
        'is_self_bookable',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_new_patient_type' => 'boolean',
            'is_self_bookable' => 'boolean',
            'sort_order' => 'integer',
        ];
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

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Types a patient may choose on the public booking page. Always a subset
     * of the active ones — hiding a type hides it from patients too.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSelfBookable(Builder $query): void
    {
        $query->active()->where('is_self_bookable', true);
    }

    /**
     * Visit types are hidden, never deleted — historical bookings point here.
     */
    public function hide(): void
    {
        $this->update(['is_active' => false]);
    }
}
