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
     * 19:00 to 03:00: open past midnight, into the next calendar day.
     */
    public function crossesMidnight(): bool
    {
        return $this->endTime() < $this->startTime();
    }

    /**
     * When the period opens and closes on a clinic day, as instants.
     *
     * An overnight period closes on the following calendar date — built from
     * that date and the wall-clock time, never by shifting the opening instant,
     * so a DST change in the night cannot move the close.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function boundsOn(Carbon $date, string $timezone): array
    {
        $closesOn = $this->crossesMidnight() ? $date->copy()->addDay() : $date;

        return [
            Carbon::parse($date->toDateString().' '.$this->startTime(), $timezone),
            Carbon::parse($closesOn->toDateString().' '.$this->endTime(), $timezone),
        ];
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
