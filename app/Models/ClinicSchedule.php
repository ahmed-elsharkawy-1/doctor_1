<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use Database\Factories\ClinicScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicSchedule extends Model
{
    /** @use HasFactory<ClinicScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'day_of_week',
        'is_open',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'is_open' => 'boolean',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return HasMany<ClinicSchedulePeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(ClinicSchedulePeriod::class)->orderBy('start_time');
    }

    /**
     * The one period that runs past midnight, if any. Two never can — both
     * would contain midnight, and periods on a day never overlap.
     */
    public function overnightPeriod(): ?ClinicSchedulePeriod
    {
        if (! $this->is_open) {
            return null;
        }

        return $this->periods->first(fn (ClinicSchedulePeriod $period) => $period->crossesMidnight());
    }
}
