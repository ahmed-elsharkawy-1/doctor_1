<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Clinic extends Model
{
    /** @use HasFactory<ClinicFactory> */
    use HasFactory;

    protected $fillable = [
        'specialty_id',
        'slug',
        'name',
        'address',
        'city',
        'latitude',
        'longitude',
        'phone',
        'timezone',
        'country_code',
        'booking_window_days',
        'first_visit_only_days',
        'slot_step_minutes',
        'patient_arrival_lead_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'booking_window_days' => 'integer',
            'first_visit_only_days' => 'integer',
            'slot_step_minutes' => 'integer',
            'patient_arrival_lead_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Specialty, $this> */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    /** @return HasMany<Doctor, $this> */
    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    /**
     * v1 runs one doctor per clinic (SPEC decision #5). This accessor is the
     * single place that assumption lives — multi-doctor changes it here.
     *
     * @return HasOne<Doctor, $this>
     */
    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class)->where('is_active', true);
    }

    /** @return BelongsToMany<User, $this> */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return HasMany<ClinicPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(ClinicPhoto::class)->orderBy('sort_order');
    }

    /** @return HasMany<VisitType, $this> */
    public function visitTypes(): HasMany
    {
        return $this->hasMany(VisitType::class)->orderBy('sort_order');
    }

    /** @return HasMany<ClinicSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(ClinicSchedule::class)->orderBy('day_of_week');
    }

    /** @return HasMany<ClinicHoliday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(ClinicHoliday::class)->orderBy('date');
    }

    /** @return HasMany<Patient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function scheduleFor(DayOfWeek $day): ?ClinicSchedule
    {
        return $this->schedules()->where('day_of_week', $day->value)->first();
    }

    /**
     * "السبت للخميس" for a run of open days, or the single day's name.
     * Reads the loaded schedules, so eager-load them on a public page.
     */
    public function workingDaysLabel(): ?string
    {
        $openDays = $this->schedules
            ->where('is_open', true)
            ->sortBy(fn (ClinicSchedule $day): int => $day->day_of_week->value);

        if ($openDays->isEmpty()) {
            return null;
        }

        $first = $openDays->first()->day_of_week;
        $last = $openDays->last()->day_of_week;

        if ($first === $last) {
            return $first->label();
        }

        return __('landing.days_range', [
            'from' => $first->label(),
            // Arabic contracts "لـ" with the article: الخميس becomes للخميس.
            // Keyed on the string, not the locale, so it is a no-op elsewhere.
            'to' => preg_replace('/^ال/u', 'لل', $last->label()),
        ]);
    }

    /**
     * What a map is pointed at: the exact coordinates when the clinic has
     * them, otherwise its written address.
     */
    public function mapQuery(): ?string
    {
        return $this->latitude !== null && $this->longitude !== null
            ? $this->latitude.','.$this->longitude
            : $this->address;
    }

    public function mapLink(): ?string
    {
        $query = $this->mapQuery();

        return $query === null ? null : 'https://maps.google.com/?q='.urlencode($query);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
