<?php

namespace App\Models;

use Database\Factories\SpecialtyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\App;

class Specialty extends Model
{
    /** @use HasFactory<SpecialtyFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name_ar',
        'name_en',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<SpecialtyDefaultVisitType, $this> */
    public function defaultVisitTypes(): HasMany
    {
        return $this->hasMany(SpecialtyDefaultVisitType::class)->orderBy('sort_order');
    }

    /** @return HasMany<Clinic, $this> */
    public function clinics(): HasMany
    {
        return $this->hasMany(Clinic::class);
    }

    public function getNameAttribute(): string
    {
        return App::getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
