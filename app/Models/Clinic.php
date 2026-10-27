<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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
        'patient_booking_window_days',
        'first_visit_only_days',
        'slot_step_minutes',
        'patient_arrival_lead_minutes',
        'tracking_delay_reduction_minutes',
        'tracking_delay_threshold_minutes',
        'self_booking_enabled',
        'whatsapp_enabled',
        'reports_enabled',
        'is_test',
        'is_active',
    ];

    /**
     * A clinic's address is printed on posters, so the old one keeps working
     * whenever it changes. Kept here rather than in the panel so a seeder or a
     * tinker fix cannot change a slug without leaving the redirect behind.
     */
    protected static function booted(): void
    {
        static::updated(function (self $clinic): void {
            if (! $clinic->wasChanged('slug')) {
                return;
            }

            $previous = $clinic->getOriginal('slug');

            if (filled($previous)) {
                ClinicSlugRedirect::query()->firstOrCreate(
                    ['slug' => $previous],
                    ['clinic_id' => $clinic->id],
                );
            }

            // Taking back an address this clinic used before: it is the live
            // one again, not a redirect to itself.
            $clinic->slugRedirects()->where('slug', $clinic->slug)->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'self_booking_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'reports_enabled' => 'boolean',
            'is_test' => 'boolean',
            'booking_window_days' => 'integer',
            'patient_booking_window_days' => 'integer',
            'first_visit_only_days' => 'integer',
            'slot_step_minutes' => 'integer',
            'patient_arrival_lead_minutes' => 'integer',
            'tracking_delay_reduction_minutes' => 'integer',
            'tracking_delay_threshold_minutes' => 'integer',
        ];
    }

    /**
     * Minutes taken off the estimated lateness before the tracking page shows
     * it. The column is this clinic's override, the config the default.
     */
    public function trackingDelayReductionMinutes(): int
    {
        return $this->tracking_delay_reduction_minutes
            ?? (int) config('clinic.tracking.delay.reduction_minutes');
    }

    /** A shown delay must be more than this for the notice to appear at all. */
    public function trackingDelayThresholdMinutes(): int
    {
        return $this->tracking_delay_threshold_minutes
            ?? (int) config('clinic.tracking.delay.threshold_minutes');
    }

    /**
     * How many days ahead a patient may book for themselves.
     *
     * The column is the per-clinic override and the config value is the system
     * default, which is the convention everything else here follows. Null
     * therefore means "the platform's default", not "the same as the clinic's
     * own window" — a clinic nobody has tuned should still keep the secretary
     * some room rather than opening the whole horizon to the public page.
     *
     * Clamped here rather than only validated on the form: a seeder, an import
     * or a hand-written SQL fix must never be able to open the public page
     * further ahead than the clinic itself takes bookings.
     */
    public function patientBookingWindowDays(): int
    {
        $window = $this->patient_booking_window_days
            ?: (int) config('clinic.defaults.patient_booking_window_days');

        return (int) max(1, min($window, $this->booking_window_days));
    }

    /**
     * Whether the public booking page is open for this clinic. A deactivated
     * clinic has no public presence at all, so it can never self-book.
     */
    /**
     * Whether WhatsApp messages go to this clinic's patients at all — a super
     * admin's switch, on unless turned off. See WhatsAppMessagingService.
     */
    public function sendsWhatsApp(): bool
    {
        return $this->whatsapp_enabled !== false;
    }

    public function allowsSelfBooking(): bool
    {
        return $this->is_active && $this->self_booking_enabled;
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

    /** @return HasOne<ClinicQrPoster, $this> */
    public function qrPoster(): HasOne
    {
        return $this->hasOne(ClinicQrPoster::class);
    }

    /** @return HasMany<ClinicQrScan, $this> */
    public function qrScans(): HasMany
    {
        return $this->hasMany(ClinicQrScan::class);
    }

    /** @return HasMany<ClinicSlugRedirect, $this> */
    public function slugRedirects(): HasMany
    {
        return $this->hasMany(ClinicSlugRedirect::class);
    }

    public function scheduleFor(DayOfWeek $day): ?ClinicSchedule
    {
        return $this->schedules()->where('day_of_week', $day->value)->first();
    }

    /**
     * The clinic day it is now, at midnight in the clinic's timezone.
     *
     * Usually the calendar date. But while last night's overnight period is
     * still open — 01:30 on a Tuesday, for a Monday that runs 19:00 to 03:00 —
     * it is still Monday: those patients are on Monday's list, and Monday must
     * not be closed out or drop off the queue until its period has ended.
     *
     * Monday also stays current while it still has a patient waiting whose
     * visit is not long over — a delay can push a shift past its own period,
     * and those patients must not vanish from the secretary's list and their
     * tracking pages at midnight. Never past the end of the clinic day.
     */
    public function currentDay(): Carbon
    {
        $now = Carbon::now($this->timezone);
        $today = Carbon::parse($now->toDateString(), $this->timezone);
        $yesterday = Carbon::parse($now->copy()->subDay()->toDateString(), $this->timezone);

        $overnight = $this->scheduleFor(DayOfWeek::fromDate($yesterday))?->overnightPeriod();

        if ($overnight !== null && $now->format('H:i') < $overnight->endTime()) {
            $wasHoliday = $this->holidays()->whereDate('date', $yesterday->toDateString())->exists();

            return $wasHoliday ? $today : $yesterday;
        }

        // Past the end of the clinic day, whatever is still waiting is over.
        if ($now->format('H:i') >= (string) config('clinic.end_of_day.closes_at')) {
            return $today;
        }

        return $this->hasPatientsStillWaiting($yesterday, $now) ? $yesterday : $today;
    }

    /**
     * Whether a day still has someone waiting — booked, in the clinic, or with
     * the doctor — whose visit ended no longer ago than the grace allows.
     * Times are stored as the clinic's wall clock.
     */
    private function hasPatientsStillWaiting(Carbon $day, Carbon $now): bool
    {
        $since = $now->copy()->subMinutes((int) config('clinic.end_of_day.waiting_grace_minutes'));

        return $this->bookings()
            ->whereDate('visit_date', $day->toDateString())
            ->whereIn('status', [BookingStatus::BOOKED, BookingStatus::ARRIVED, BookingStatus::WITH_DOCTOR])
            ->where('end_at', '>=', $since->format('Y-m-d H:i:s'))
            ->exists();
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
