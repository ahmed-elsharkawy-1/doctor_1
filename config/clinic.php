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
        'name' => env('SUPER_ADMIN_NAME') ?: 'Super Admin',
        'email' => env('SUPER_ADMIN_EMAIL') ?: 'admin@doctor1.test',
        'password' => env('SUPER_ADMIN_PASSWORD') ?: 'password',
    ],

    /*
    | Seeded onto a clinic when it is created. Editable per clinic afterwards.
    */
    'defaults' => [
        'timezone' => env('CLINIC_DEFAULT_TIMEZONE', 'Africa/Cairo'),
        'booking_window_days' => 7,
        'first_visit_only_days' => 60,
        // Null means every visit type sets its own grid from its own
        // duration. A number overrides that with fixed rolling starts.
        'slot_step_minutes' => null,
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
    | Filament panel, for the platform operator only.
    */
    'panel' => [
        'path' => env('CLINIC_PANEL_PATH', 'admin'),
    ],

    /*
    | Public doctor landing page — `/{slug}`.
    |
    | Path-based rather than a subdomain: one certificate, one origin, and the
    | domain's search authority stays in one place.
    */
    'landing' => [
        // Slugs the operator may not take, because a route already owns them.
        'reserved' => [
            'app', 'admin', 'docs', 'api', 'livewire', 'storage', 'up', 'login',
            'booking', 'review',
            // The path tracking links used before they were made readable.
            'b',
        ],
    ],

    /*
    | The public doctor page's own content.
    */
    /*
    | Platform branding. Files live in public/images/brand and are replaced by
    | dropping new ones in — nothing reads them by any other name. Point a key
    | somewhere else and every page follows.
    |
    | `logo` is the mark on its own blue tile and is safe anywhere. `logo_white`
    | is knocked out in white with a transparent background, so it is legible
    | *only* on the brand colour or another dark surface.
    */
    'brand' => [
        'name' => env('CLINIC_BRAND_NAME', 'العيادة'),
        'color' => '#0174D6',
        'logo' => 'images/brand/logo.png',
        'logo_white' => 'images/brand/logo-white.png',
        'cover' => 'images/brand/cover.jpg',
        'favicon' => 'images/brand/favicon.png',
        'apple_touch_icon' => 'images/brand/apple-touch-icon.png',
        'icon_192' => 'images/brand/icon-192.png',
    ],

    'public' => [
        // Photos and portraits. `public` is served through the storage symlink,
        // which the container creates on boot.
        'disk' => env('CLINIC_PUBLIC_DISK', 'public'),
        'photo_max_kb' => 4096,

        // Stock avatars shown until a doctor uploads a portrait. Files are
        // named after App\Enums\DoctorSex — male.<ext> and female.<ext>.
        'avatar_path' => 'images/avatars',
        'avatar_extension' => env('CLINIC_AVATAR_EXTENSION', 'svg'),

        // Icon keys a treatment area may use. The page draws the SVG itself,
        // so nothing an operator types is ever rendered as markup.
        'icons' => [
            'activity',
            'spine',
            'joint',
            'stethoscope',
            'heart',
            'baby',
            'tooth',
            'eye',
            'brain',
            'bone',
        ],

        // Tabs the page offers. `blog` is deliberately absent for now.
        'tabs' => ['overview', 'services', 'contact', 'location'],
    ],

    /*
    | Patient booking-tracking page (SPEC v1.1 §7).
    |
    | The link goes out over WhatsApp, so the token is the whole secret and
    | the path is kept short. A signed URL would put a 64-character signature
    | in the message instead.
    */
    'tracking' => [
        // Readable, and stable for ever: a WhatsApp template's URL button has
        // one fixed base, so the path cannot vary by doctor — the token
        // carries the identity.
        'path' => 'booking',

        // Paths that shipped earlier. Links already sitting in patients'
        // WhatsApp history must never stop working, so these keep resolving.
        'legacy_paths' => ['b'],
        // Bytes of randomness; the stored token is twice this in hex.
        'token_bytes' => 16,
        // How often the waiting page re-reads its position.
        'refresh_seconds' => (int) env('CLINIC_TRACKING_REFRESH_SECONDS', 30),
    ],

    /*
    | Patient review page, opened from the visit-completed WhatsApp message.
    | Shares the booking's tracking token — it is already that patient's
    | secret for that visit, and a second one would be one more thing to leak.
    */
    'review' => [
        'path' => 'review',
        'comment_max' => 600,
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

        // The shared test account the handoff page documents. Its clinic is
        // the only one the page will ever show live booking links for, so a
        // real clinic's patients can never appear there.
        'demo_account' => env('CLINIC_DOCS_DEMO_EMAIL', 'doctor@doctor1.test'),

        // The clinic the live end-to-end flow is exercised on. Listed on the
        // handoff page for access, but never for its bookings — those name
        // patients, and this one takes real ones.
        'pilot_account' => env('CLINIC_DOCS_PILOT_EMAIL', 'drseham@gmail.com'),
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
