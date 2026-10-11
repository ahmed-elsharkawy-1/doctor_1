<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The operator's adjustments to one clinic's QR poster. Every null column
 * means "use the default" — see App\Services\V1\QrPoster\QrPosterService.
 */
class ClinicQrPoster extends Model
{
    protected $fillable = [
        'clinic_id',
        'name',
        'title',
        'call_to_action',
        'name_size',
        'title_size',
        'qr_size',
    ];

    protected function casts(): array
    {
        return [
            'name_size' => 'integer',
            'title_size' => 'integer',
            'qr_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
