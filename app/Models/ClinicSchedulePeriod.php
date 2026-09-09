<?php

namespace App\Models;

use Database\Factories\ClinicSchedulePeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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

    /**
     * The period as a patient reads it: "1:00 مساءً – 3:00 مساءً".
     *
     * Both ends name their own half of the day, even when they share it.
     * Writing it once leaves the opening time's meridiem merely implied, and
     * a misread here costs someone a wasted trip to the clinic.
     */
    public function readableRange(): string
    {
        return $this->readableTime($this->startTime())
            .' – '
            .$this->readableTime($this->endTime());
    }

    private function readableTime(string $time): string
    {
        $moment = Carbon::createFromFormat('H:i', $time);

        $meridiem = $moment->format('A') === 'AM'
            ? __('schedule.am')
            : __('schedule.pm');

        return $moment->format('g:i').' '.$meridiem;
    }
}
