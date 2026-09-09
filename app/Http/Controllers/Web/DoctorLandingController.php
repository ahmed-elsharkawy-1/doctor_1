<?php

namespace App\Http\Controllers\Web;

use App\Enums\DayOfWeek;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * The clinic's public page — the entrance to the whole funnel.
 *
 * Read-only, unauthenticated, and indexable: this is the one page in the
 * system that search engines are meant to find. It carries no booking form.
 * A patient asks on WhatsApp and the clinic takes the booking, which is the
 * flow the clinic already runs (SPEC v1.2).
 */
class DoctorLandingController extends Controller
{
    public function __invoke(string $slug): View
    {
        App::setLocale(config('clinic.api.default_locale'));

        $clinic = Clinic::query()
            ->with([
                'specialty',
                'doctor.treatmentAreas' => fn ($query) => $query->active(),
                'schedules.periods',
                'photos',
                // Hidden visit types are hidden from patients too.
                'visitTypes' => fn ($query) => $query->active(),
            ])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $phone = $clinic->phone === null ? null : PhoneNumber::tryParse($clinic->phone);
        $openDays = $clinic->schedules->where('is_open', true);

        return view('landing.show', [
            'clinic' => $clinic,
            'doctor' => $clinic->doctor,
            'phone' => $phone,
            // wa.me wants the international number with no punctuation.
            'whatsapp' => $phone === null ? null : ltrim((string) $phone, '+'),
            'openDays' => $openDays,
            'visitTypes' => $clinic->visitTypes,
            'treatmentAreas' => $clinic->doctor?->treatmentAreas ?? collect(),
            'photos' => $clinic->photos,
            'workingDaysLabel' => $this->workingDaysLabel($openDays),
            'visitLengthLabel' => $this->visitLengthLabel($clinic),
            'todayDayOfWeek' => DayOfWeek::fromDate(now($clinic->timezone)),
        ]);
    }

    /**
     * "السبت للخميس" for a run of days, or the single day's name.
     *
     * @param  Collection<int, ClinicSchedule>  $openDays
     */
    private function workingDaysLabel(Collection $openDays): ?string
    {
        if ($openDays->isEmpty()) {
            return null;
        }

        $sorted = $openDays->sortBy(fn (ClinicSchedule $day): int => $day->day_of_week->value);
        $first = $sorted->first()->day_of_week;
        $last = $sorted->last()->day_of_week;

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
     * The headline consultation length: the new-patient visit type if the
     * clinic marked one, otherwise the first it offers.
     */
    private function visitLengthLabel(Clinic $clinic): ?string
    {
        $visitType = $clinic->visitTypes->firstWhere('is_new_patient_type', true)
            ?? $clinic->visitTypes->first();

        return $visitType === null
            ? null
            : __('landing.minutes', ['count' => $visitType->duration_minutes]);
    }
}
