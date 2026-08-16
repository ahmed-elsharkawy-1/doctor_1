<?php

/*
|--------------------------------------------------------------------------
| Clinic system defaults
|--------------------------------------------------------------------------
|
| System-wide defaults only. Anything a clinic owner can change lives as a
| column on the `clinics` table and is seeded from here at creation time.
| Nothing configurable may be hardcoded in application code.
|
*/

return [

    /*
    | Bootstrap account created by DatabaseSeeder. Override per environment.
    */
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@doctor1.test'),
        'password' => env('SUPER_ADMIN_PASSWORD', 'password'),
    ],

    /*
    | Seeded onto a clinic when it is created. Editable per clinic afterwards.
    */
    'defaults' => [
        'timezone' => env('CLINIC_DEFAULT_TIMEZONE', 'Africa/Cairo'),
        'booking_window_days' => 7,
        'first_visit_only_days' => 60,
        'slot_step_minutes' => 10,
        'patient_arrival_lead_minutes' => 30,
    ],

    /*
    | Owner-editable app settings with fixed option sets.
    */
    'settings' => [
        'patient_arrival_lead_minute_options' => [15, 20, 30, 45, 60],
    ],

    'messaging' => [
        'driver' => env('CLINIC_MESSAGING_DRIVER', 'log'),
    ],

    /*
    | Phone normalisation. Numbers are stored E.164 and displayed nationally.
    */
    'phone' => [
        'default_country' => env('CLINIC_DEFAULT_COUNTRY', 'EG'),
        'countries' => [
            'EG' => ['dial_code' => '20', 'trunk_prefix' => '0', 'national_length' => 10],
            'AE' => ['dial_code' => '971', 'trunk_prefix' => '0', 'national_length' => 9],
            'SA' => ['dial_code' => '966', 'trunk_prefix' => '0', 'national_length' => 9],
        ],
        'mask' => [
            'visible_prefix' => 4,
            'visible_suffix' => 4,
            'mask_character' => '*',
            'masked_length' => 3,
        ],
    ],

    /*
    | Patient ID code generation — see SPEC §5.3.
    | Derived from the database id and assigned once after insert.
    */
    'patient_code' => [
        'start_at' => 60000,
        'step' => 1,
        'min_length' => 5,
    ],

    /*
    | Scheduling.
    */
    'schedule' => [
        // Business week starts Saturday; see App\Enums\DayOfWeek.
        'week_start_day' => 6,
        'min_period_minutes' => 5,
        'max_periods_per_day' => 6,
    ],

    /*
    | End-of-day housekeeping (SPEC §5.4).
    */
    'end_of_day' => [
        'run_at' => '00:05',
    ],

    /*
    | Retention reporting (SPEC §5.6).
    */
    'retention' => [
        'default_period' => 'this_month',
    ],

    /*
    | API surface.
    */
    'api' => [
        'pagination' => [
            'per_page' => 15,
            'max_per_page' => 50,
        ],
        'locales' => ['ar', 'en'],
        'default_locale' => 'ar',
        'token_name' => 'mobile',
    ],

    /*
    | Browsable API reference, rendered from docs/api/v1/openapi.yaml.
    |
    | Off in production by default: the spec is not secret, but publishing a
    | full map of the API is not something to do by accident.
    */
    'docs' => [
        'enabled' => (bool) env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),
        'path' => 'docs/api',
        'spec' => 'docs/api/v1/openapi.yaml',
        'hidden_tags' => ['Postpone', 'Patients', 'Reports'],
    ],

    /*
    | Wire formats returned by the API (SPEC §6.4).
    */
    'formats' => [
        'date' => 'Y-m-d',
        'time' => 'H:i',
        'datetime' => DateTimeInterface::ATOM,
        'money_decimals' => 2,
    ],
];
