<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Support\PhoneNumber;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A place the doctor works from (ELA-16).
 *
 * The clinic stays the tenant: patients, visit types and prices, the no-show
 * block and the WhatsApp sender are the doctor's, across every branch. A
 * branch has its own name, address, location and, optionally, phone.
 *
 * Address, location and phone fall back to the clinic's, so the branch every
 * existing clinic was given reads exactly as the clinic did.
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'name',
        'address',
        'latitude',
        'longitude',
        'phone',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * This branch's week, Saturday first (ELA-20).
     *
     * @return HasMany<ClinicSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ClinicSchedule::class)->orderBy('day_of_week');
    }

    public function scheduleFor(DayOfWeek $day): ?ClinicSchedule
    {
        return $this->schedules()->where('day_of_week', $day->value)->with('periods')->first();
    }

    /**
     * Whether a holiday closes this branch on that date: one of its own, or
     * one for all branches.
     */
    public function isClosedOn(Carbon|string $date): bool
    {
        $date = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return ClinicHoliday::query()->closing($this)->whereDate('date', $date)->exists();
    }

    /**
     * Whether any day of the week is open: a branch with none can't take a
     * booking yet (ELA-16).
     */
    public function hasWorkingHours(): bool
    {
        return $this->schedules()->where('is_open', true)->whereHas('periods')->exists();
    }

    /**
     * "السبت لللخميس"-style label for the days this branch is open, as the
     * public page shows the clinic's (ELA-18). Null with no open day.
     */
    public function workingDaysLabel(): ?string
    {
        $open = $this->schedules()->where('is_open', true)->whereHas('periods')->get()
            ->map(fn (ClinicSchedule $day) => $day->day_of_week)
            ->sortBy(fn (DayOfWeek $day) => $day->value)
            ->values();

        if ($open->isEmpty()) {
            return null;
        }

        // Consecutive days read as a range, a gap starts a new part: Sunday,
        // Tuesday and Friday never read as "Sunday to Friday".
        $runs = [];

        foreach ($open as $day) {
            $last = array_key_last($runs);

            if ($last !== null && end($runs[$last])->value === $day->value - 1) {
                $runs[$last][] = $day;
            } else {
                $runs[] = [$day];
            }
        }

        return collect($runs)->map(function (array $run): string {
            if (count($run) === 1) {
                return $run[0]->label();
            }

            if (count($run) === 2) {
                return $run[0]->label().'، '.$run[1]->label();
            }

            return __('landing.days_range', [
                'from' => $run[0]->label(),
                'to' => preg_replace('/^ال/u', 'لل', end($run)->label()),
            ]);
        })->join('، ');
    }

    /**
     * A Google Maps link to the branch: its pin, else its address.
     */
    public function mapLink(): ?string
    {
        $query = $this->coordinates() ?? $this->displayAddress();

        return $query === null ? null : 'https://maps.google.com/?q='.urlencode($query);
    }

    /**
     * The clinic's original branch: the one every existing clinic was given,
     * and the one whose address and pin are the clinic's own.
     */
    public function isFirst(): bool
    {
        return $this->id === (int) Branch::query()->where('clinic_id', $this->clinic_id)->min('id');
    }

    public function displayAddress(): ?string
    {
        return filled($this->address) ? $this->address : $this->clinic->address;
    }

    /**
     * The `lat,lng` pair, or null when neither the branch nor the clinic has
     * a pin. Both halves come from the same row, never one from each.
     */
    public function coordinates(): ?string
    {
        if ($this->latitude !== null && $this->longitude !== null) {
            return $this->latitude.','.$this->longitude;
        }

        if (filled($this->address)) {
            return null;
        }

        $clinic = $this->clinic;

        return $clinic->latitude !== null && $clinic->longitude !== null
            ? $clinic->latitude.','.$clinic->longitude
            : null;
    }

    /**
     * The number patients call about this branch: its own, else the doctor's
     * main booking number (ELA-16).
     */
    public function contactPhone(): ?PhoneNumber
    {
        $clinic = $this->clinic;
        $raw = filled($this->phone) ? $this->phone : $clinic->phone;

        return $raw === null ? null : PhoneNumber::tryParse($raw, $clinic->country_code);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
