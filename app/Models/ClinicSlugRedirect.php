<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address a clinic's page used to live at, kept so printed links still
 * work. Written by Clinic itself whenever its slug changes.
 */
class ClinicSlugRedirect extends Model
{
    protected $fillable = [
        'clinic_id',
        'slug',
    ];

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
