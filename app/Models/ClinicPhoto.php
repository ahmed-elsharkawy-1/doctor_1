<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One picture in صور العيادة.
 */
class ClinicPhoto extends Model
{
    protected $fillable = [
        'clinic_id',
        'path',
        'caption',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Null when the file has gone missing, so the page can skip it rather
     * than render a broken image.
     */
    public function url(): ?string
    {
        if ($this->path === null || $this->path === '') {
            return null;
        }

        return Storage::disk(config('clinic.public.disk'))->url($this->path);
    }
}
