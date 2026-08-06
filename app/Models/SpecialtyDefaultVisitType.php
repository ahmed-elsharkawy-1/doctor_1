<?php

namespace App\Models;

use Database\Factories\SpecialtyDefaultVisitTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\App;

/**
 * Seed source for a new clinic's visit types. Never read after provisioning —
 * once a clinic exists it owns its own visit_types rows.
 */
class SpecialtyDefaultVisitType extends Model
{
    /** @use HasFactory<SpecialtyDefaultVisitTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'specialty_id',
        'name_ar',
        'name_en',
        'duration_minutes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Specialty, $this> */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function getNameAttribute(): string
    {
        return App::getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
