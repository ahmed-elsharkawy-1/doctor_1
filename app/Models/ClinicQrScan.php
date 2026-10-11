<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One clinic's QR poster visits on one of its days.
 */
class ClinicQrScan extends Model
{
    protected $fillable = [
        'clinic_id',
        'scan_date',
        'scans',
    ];

    protected function casts(): array
    {
        return [
            'scan_date' => 'date:Y-m-d',
            'scans' => 'integer',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
