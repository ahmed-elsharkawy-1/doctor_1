<?php

namespace App\Models;

use Database\Factories\ClinicSchedulePeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicSchedulePeriod extends Model
{
    /** @use HasFactory<ClinicSchedulePeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_schedule_id',
        'start_time',
        'end_time',
    ];

    /** @return BelongsTo<ClinicSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ClinicSchedule::class, 'clinic_schedule_id');
    }

    /**
     * Times are stored as `HH:MM:SS`; the API and UI both work in `HH:MM`.
     */
    public function startTime(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function endTime(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }
}
