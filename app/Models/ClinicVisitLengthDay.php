<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One date on which every visit at the clinic takes `minutes`, whatever the
 * visit type's own length. See Clinic::visitMinutesOn().
 */
class ClinicVisitLengthDay extends Model
{
    protected $fillable = [
        'clinic_id',
        'date',
        'minutes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
