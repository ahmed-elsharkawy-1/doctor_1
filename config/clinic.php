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
        // How far ahead a patient may book for themselves: today plus four.
        //
        // It was three, on the reasoning that the days beyond it stayed
        // reservable by phone so the secretary always had somewhere to put a
        // caller. Three proved too short a horizon for a patient planning
        // around work; much past five and the list stops being something you
        // read at a glance and becomes something you scroll.
        //
        // Never exceeds the clinic's own window — see
        // Clinic::patientBookingWindowDays().
        'patient_booking_window_days' => 5,
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
    | The lock every write competing for a clinic's day queues behind.
    |
    | One booking takes milliseconds, so the wait only has to cover a genuine
    | collision — two people reaching for the same minute — not a queue.
    */
    'locking' => [
        'day_lock_ttl_seconds' => 10,
        'day_lock_wait_seconds' => 5,
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
        // The platform's name and slogan, shown on every public page. Change
        // them here and every page follows. Approved WhatsApp templates carry
        // their own fixed copy of the slogan and do not.
        'name' => env('CLINIC_BRAND_NAME', 'العيادة'),
        'slogan' => 'حجزك أسهل، وقتك أثمن',
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
    | Patient self-booking — the public page at `/{slug}/book`.
    |
    | Off per clinic until the operator switches it on (see the
    | `self_booking_enabled` column); these are only the shared dials.
    |
    | Two short-lived credentials live here. A slot hold is a claim on a time
    | nobody has paid for yet, and a verified session is a claim to own a phone
    | number — both are kept brief on purpose, because both are things somebody
    | could walk away from and leave sitting.
    */
    'self_booking' => [
        // The second path segment: /{slug}/book.
        'path' => 'book',

        // Whether the public page quotes a price.
        //
        // Off until a clinic asks for it. A price shown to a patient is a
        // quote, and the clinics piloting this would rather discuss cost at
        // the desk than have a number on a screen treated as a commitment.
        // The figure is still stored on the visit type and still snapshotted
        // onto the booking either way — this hides it, it does not stop
        // charging for anything.
        'show_price' => (bool) env('CLINIC_SELF_BOOKING_SHOW_PRICE', false),

        // How long a tapped slot is held before it returns to the pool. Long
        // enough to finish the form, short enough that an abandoned browser
        // does not block a nearly-full day.
        'hold_ttl_minutes' => 5,

        // How long "this browser proved it owns that number" stays true.
        // Checked on read against the clock, not delegated to the session
        // lifetime — that is a global a deploy could change underneath us.
        'verified_session_minutes' => 20,

        /*
        | Whether a patient must prove their phone before booking.
        |
        | TEMPORARY — off only because Meta will not issue an authentication
        | template for this account yet (see
        | docs/whatsapp/whatsapp-authentication-unblock.md). With it off the
        | number is still parsed and normalised, and still the identity the
        | booking is filed under; it is simply taken on trust.
        |
        | That trust has a real cost: patients are matched on phone alone, so
        | an unverified number files a visit into somebody else's medical
        | history. Turn this back on the moment a code can actually be sent.
        */
        'require_otp' => (bool) env('CLINIC_SELF_BOOKING_REQUIRE_OTP', true),

        /*
        | Grouping the day's slots for display.
        |
        | A ten-minute visit across an eight-hour day is forty-eight buttons,
        | and a patient scanning forty-eight buttons is not choosing, they are
        | searching. So they are shown as a few collapsed stretches instead.
        |
        | Presentation only. Nothing here reaches the slot grid itself, what
        | can be held, or what can be booked — see App\Services\V1\Booking\
        | SlotGrouper, which is a pure function over slots that already exist.
        */
        'slot_groups' => [
            // Nine is three full rows of the three-column grid on a phone, so
            // an opened stretch looks deliberate rather than ragged.
            'max_per_group' => (int) env('CLINIC_SLOTS_PER_GROUP', 9),

            // Below this a day is short enough to read at a glance, and
            // grouping would add a tap that buys nothing.
            'min_to_group' => (int) env('CLINIC_SLOTS_MIN_TO_GROUP', 10),

            // Share of a stretch still free. Contiguous on purpose: a gap
            // between them would leave some stretches with no label at all.
            'busy_at' => 0.6,
            'scarce_at' => 0.3,
        ],

        'otp' => [
            // Mirrors clinic.messaging.driver: `log` writes the code to the
            // log so the whole flow works before Meta approves anything.
            'driver' => env('CLINIC_OTP_DRIVER', 'log'),
            // Four, not six. The page draws one box per digit, and four is
            // what a patient can hold in their head between the message and
            // the field. The code is short-lived, capped at five guesses and
            // burned on the fifth, so the odds a guesser beats it are the
            // limits' business, not the length's.
            'length' => 4,
            'ttl_minutes' => 10,

            /*
            | A code that is always the same, for walking the flow by hand
            | without fishing the real one out of the log.
            |
            | Refused outright in production — a known code is no check at
            | all, and the one thing this mechanism exists to prove is that a
            | stranger is not the person they claim to be. Leave it unset
            | anywhere that matters.
            */
            'fixed_code' => env('CLINIC_OTP_FIXED_CODE'),

            /*
            | Lets the `log` driver run on a production host, for walking the
            | booking flow before an authentication template exists.
            |
            | Codes stay random — this only permits writing them to the log,
            | where the person running the test can read their own. It does
            | NOT relax `fixed_code`, which stays refused in production: a
            | code everyone knows would let a stranger verify somebody else's
            | number and read their appointment off the next screen.
            */
            'allow_log_in_production' => (bool) env('CLINIC_OTP_ALLOW_LOG_IN_PRODUCTION', false),

            /*
            | Lets `fixed_code` work on a production host.
            |
            | Its own switch, separate from the one above, because it is the
            | more dangerous of the two: a code everyone knows lets anyone
            | verify a number they do not own and read that patient's name and
            | appointment off the next screen. Only safe while a deployment has
            | no real patients on it. Unset it before it does.
            */
            'allow_fixed_in_production' => (bool) env('CLINIC_OTP_ALLOW_FIXED_IN_PRODUCTION', false),

            // Seconds before a new code may be requested.
            'resend_cooldown' => (int) env('CLINIC_OTP_RESEND_COOLDOWN', 60),
            // A public form that sends messages is a way to bill us and to
            // pester a stranger, so it is capped from both directions.
            // Env-backed so a local run can walk the flow more than three
            // times in an hour.
            'max_per_phone_hour' => (int) env('CLINIC_OTP_MAX_PER_PHONE_HOUR', 3),
            'max_per_ip_hour' => (int) env('CLINIC_OTP_MAX_PER_IP_HOUR', 10),
            // Wrong guesses before the code is burned. The phone may ask for
            // another, subject to the hourly cap.
            'max_attempts' => 5,
        ],
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
    | An optional password in front of every web page, for a non-production
    | copy. Unset means off — the default, staging included. See App\Http\
    | Middleware\StagingGate, which also keeps non-production out of search
    | results whether or not this is set. The API, the WhatsApp webhook and
    | the health check stay open whatever this says.
    */
    'staging_gate' => [
        'user' => env('STAGING_GATE_USER', 'team'),
        'password' => env('STAGING_GATE_PASSWORD'),
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
