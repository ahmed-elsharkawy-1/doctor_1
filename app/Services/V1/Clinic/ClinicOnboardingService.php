<?php

namespace App\Services\V1\Clinic;

use App\Actions\Clinic\ProvisionClinicAction;
use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\DayOfWeek;
use App\Enums\DoctorSex;
use App\Enums\UserRole;
use App\Models\Clinic;
use App\Models\Specialty;
use App\Models\User;
use App\Services\V1\Settings\ScheduleService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds a real clinic — clinic, doctor, logins and a starting week — from one
 * description, so the exact same thing can be run on staging, checked, and
 * then run on production.
 *
 * Creates, never updates: a clinic that already exists is refused, so running
 * a description twice cannot quietly rewrite a live clinic. Everything is
 * written in one transaction — a failure leaves nothing behind.
 *
 * The description is a plain array (the console command reads it from JSON):
 *
 *     clinic:   name, slug, specialty (slug), phone, address?, city?,
 *               country_code?, self_booking_enabled?, whatsapp_enabled?,
 *               reports_enabled?
 *     doctor:   name, title?, sex?, phone? (defaults to the clinic's)
 *     accounts: [{name, email, main?}] — exactly one `main`: the doctor's
 *               own login, linked to the doctor, with reports access
 *     schedule: {saturday: [["17:00", "23:00"]], …} — a day left out is closed
 *
 * Passwords are generated here and returned once; they are never part of the
 * description, which may be kept or shared.
 */
class ClinicOnboardingService
{
    public function __construct(
        private readonly ProvisionClinicAction $provision,
        private readonly ScheduleService $schedules,
    ) {}

    /**
     * Checks a description against everything the database and the app's own
     * rules would refuse, before anything is written.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed> the validated description
     *
     * @throws ValidationException
     */
    public function validate(array $spec): array
    {
        $days = array_map(fn (DayOfWeek $day) => $this->dayKey($day), DayOfWeek::week());

        $validator = Validator::make($spec, [
            'clinic' => ['required', 'array'],
            'clinic.name' => ['required', 'string', 'max:255'],
            'clinic.slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::notIn(config('clinic.landing.reserved')),
                Rule::unique('clinics', 'slug'),
                // An address another clinic gave up still leads to it.
                Rule::unique('clinic_slug_redirects', 'slug'),
            ],
            'clinic.specialty' => ['required', 'string', Rule::exists('specialties', 'slug')],
            'clinic.phone' => ['required', 'string', 'max:20'],
            'clinic.address' => ['nullable', 'string', 'max:255'],
            'clinic.city' => ['nullable', 'string', 'max:120'],
            'clinic.country_code' => ['nullable', 'string', Rule::in(array_keys(config('clinic.phone.countries')))],
            'clinic.self_booking_enabled' => ['sometimes', 'boolean'],
            'clinic.whatsapp_enabled' => ['sometimes', 'boolean'],
            'clinic.reports_enabled' => ['sometimes', 'boolean'],

            'doctor' => ['required', 'array'],
            'doctor.name' => ['required', 'string', 'max:255'],
            'doctor.title' => ['nullable', 'string', 'max:255'],
            'doctor.sex' => ['nullable', Rule::enum(DoctorSex::class)],
            'doctor.phone' => ['nullable', 'string', 'max:20'],

            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.email' => ['required', 'email', 'max:255', 'distinct:ignore_case', Rule::unique('users', 'email')],
            'accounts.*.main' => ['sometimes', 'boolean'],

            'schedule' => ['sometimes', 'array'],
            'schedule.*' => ['array'],
            'schedule.*.*' => ['array', 'size:2'],
            'schedule.*.*.*' => ['date_format:H:i'],
        ]);

        $validator->after(function ($validator) use ($spec, $days): void {
            $country = $spec['clinic']['country_code'] ?? config('clinic.phone.default_country');
            $phone = $this->phone($spec['clinic']['phone'] ?? null, $country);

            if ($phone === null) {
                $validator->errors()->add('clinic.phone', 'The clinic phone is not a valid number.');
            } elseif (User::query()->where('phone', $phone)->exists()) {
                // The doctor's login carries the clinic number, and logins'
                // numbers are unique.
                $validator->errors()->add('clinic.phone', 'Another login already uses this phone number.');
            }

            if (filled($spec['doctor']['phone'] ?? null) && $this->phone($spec['doctor']['phone'], $country) === null) {
                $validator->errors()->add('doctor.phone', 'The doctor phone is not a valid number.');
            }

            $mains = collect($spec['accounts'] ?? [])->filter(fn ($account) => (bool) ($account['main'] ?? false));

            if ($mains->count() !== 1) {
                $validator->errors()->add('accounts', 'Exactly one account must be the main (doctor) account.');
            }

            foreach (array_keys($spec['schedule'] ?? []) as $day) {
                if (! in_array($day, $days, true)) {
                    $validator->errors()->add('schedule', "Unknown day \"{$day}\". Use: ".implode(', ', $days).'.');
                }
            }
        });

        return $validator->validate();
    }

    /**
     * Creates the clinic and returns the logins with their one-time passwords.
     *
     * @param  array<string, mixed>  $spec
     * @return array{clinic: Clinic, accounts: list<array{name: string, email: string, password: string, main: bool}>}
     *
     * @throws ValidationException
     */
    public function onboard(array $spec): array
    {
        $spec = $this->validate($spec);

        return DB::transaction(function () use ($spec): array {
            $country = $spec['clinic']['country_code'] ?? config('clinic.phone.default_country');
            $phone = $this->phone($spec['clinic']['phone'], $country);
            $defaults = config('clinic.defaults');

            $clinic = Clinic::query()->create([
                'specialty_id' => Specialty::query()->where('slug', $spec['clinic']['specialty'])->value('id'),
                'name' => $spec['clinic']['name'],
                'slug' => $spec['clinic']['slug'],
                'address' => $spec['clinic']['address'] ?? null,
                'city' => $spec['clinic']['city'] ?? null,
                'phone' => $phone,
                'country_code' => $country,
                'timezone' => $defaults['timezone'],
                'booking_window_days' => $defaults['booking_window_days'],
                'patient_booking_window_days' => $defaults['patient_booking_window_days'],
                'first_visit_only_days' => $defaults['first_visit_only_days'],
                'slot_step_minutes' => $defaults['slot_step_minutes'],
                'patient_arrival_lead_minutes' => $defaults['patient_arrival_lead_minutes'],
                'self_booking_enabled' => (bool) ($spec['clinic']['self_booking_enabled'] ?? false),
                'whatsapp_enabled' => (bool) ($spec['clinic']['whatsapp_enabled'] ?? false),
                'reports_enabled' => (bool) ($spec['clinic']['reports_enabled'] ?? false),
                'is_test' => false,
                'is_active' => true,
            ]);

            // The specialty's visit types (priced at zero for the doctor to
            // set) and seven closed days — the same as a clinic created in
            // the panel. No password, so it adds no shared login of its own.
            $this->provision->execute($clinic);

            $doctor = $clinic->doctors()->create([
                'name' => $spec['doctor']['name'],
                'title' => $spec['doctor']['title'] ?? null,
                'sex' => $spec['doctor']['sex'] ?? null,
                'phone' => filled($spec['doctor']['phone'] ?? null)
                    ? $this->phone($spec['doctor']['phone'], $country)
                    : $phone,
                'is_active' => true,
            ]);

            $accounts = [];

            foreach ($spec['accounts'] as $account) {
                $main = (bool) ($account['main'] ?? false);
                $password = Str::password(12, symbols: false);

                $user = new User([
                    'name' => $account['name'],
                    'email' => Str::lower($account['email']),
                    'password' => $password,
                    'role' => UserRole::CLINIC,
                    // Only the doctor's own login carries a number and the
                    // link to the doctor, as on the existing clinics.
                    'phone' => $main ? $phone : null,
                    'doctor_id' => $main ? $doctor->id : null,
                    'locale' => config('clinic.api.default_locale'),
                    'is_active' => true,
                    'can_access_reports' => $main,
                ]);
                $user->email_verified_at = now();
                $user->save();
                $user->clinics()->attach($clinic->id);

                $accounts[] = ['name' => $user->name, 'email' => $user->email, 'password' => $password, 'main' => $main];
            }

            // Through the same service the app uses, so the same period rules
            // apply: an open day needs a period, periods end after they start
            // and never overlap.
            foreach (DayOfWeek::week() as $day) {
                $periods = $spec['schedule'][$this->dayKey($day)] ?? [];

                if ($periods === []) {
                    continue;
                }

                $this->schedules->updateDay($clinic, ScheduleDayData::fromArray($day, [
                    'is_open' => true,
                    'periods' => array_map(fn (array $period) => [
                        'start_time' => $period[0],
                        'end_time' => $period[1],
                    ], $periods),
                ]));
            }

            return ['clinic' => $clinic->refresh(), 'accounts' => $accounts];
        });
    }

    public function dayKey(DayOfWeek $day): string
    {
        return Str::lower($day->name);
    }

    private function phone(mixed $number, string $country): ?string
    {
        return is_string($number) && $number !== ''
            ? PhoneNumber::tryParse($number, $country)?->e164
            : null;
    }
}
