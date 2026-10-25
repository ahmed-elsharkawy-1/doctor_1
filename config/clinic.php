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

/*
| The end of the clinic day, the next morning, in each clinic's own time.
|
| One value for every clinic and every rule that needs it: an overnight
| period must close by it, the day before is not closed out until it, "today"
| stays on the evening while patients are still waiting up to it, and a delay
| may not push anyone past it. Kept in one place so the rules cannot drift
| apart, as 05:00 and 06:00 once did.
*/
$endOfClinicDay = env('CLINIC_DAY_CLOSES_AT', '06:00');

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
        // How long before the appointment the WhatsApp reminder goes out.
        'reminder_lead_minutes' => (int) env('CLINIC_REMINDER_LEAD_MINUTES', 60),
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
        // A period may run past midnight (19:00–03:00) but must close by the
        // end of the clinic day. Its bookings stay on the day it opened.
        'latest_overnight_close' => $endOfClinicDay,
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
        // When a clinic's day is over: the end of the clinic day, above. Not
        // midnight — evening shifts run past 12, and the doctor is still
        // seeing yesterday's list at 00:30. Until then the day before is left
        // open, so nobody still waiting is marked a no-show.
        'closes_at' => $endOfClinicDay,

        // After midnight the evening stays "today" — on the secretary's list,
        // the home screen and the tracking page — while it still has a
        // patient waiting whose visit ended no longer ago than this. A shift
        // a delay pushed past its own period does not vanish at 00:00; a
        // patient simply left unmarked at 21:00 does not hold the day open.
        'waiting_grace_minutes' => 120,
    ],

    /*
    | Delaying the rest of today's patients — POST /api/v1/emergency.
    */
    'delay' => [
        // The only delays the app offers, in minutes.
        'options' => [15, 30, 60, 90, 120, 150, 180, 210, 240, 270, 300],

        // The same delay sent again within this long — a double tap, a
        // retried request — returns the first result instead of moving
        // everyone a second time.
        'replay_seconds' => 120,
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
            'booking', 'review', 'reports', 'handoff',
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

    /*
    | The platform's own landing page, at the bare domain.
    |
    | Doctors reach the team directly — WhatsApp, phone, email. Each is
    | optional: a channel left empty is simply not shown, so the page never
    | offers a button that reaches nobody.
    | Numbers are written however they are dialled locally (01012345678).
    */
    'site' => [
        'whatsapp' => env('CLINIC_SITE_WHATSAPP'),
        'phone' => env('CLINIC_SITE_PHONE'),
        'email' => env('CLINIC_SITE_EMAIL'),
        'social' => array_filter([
            'facebook' => env('CLINIC_SITE_FACEBOOK'),
            'instagram' => env('CLINIC_SITE_INSTAGRAM'),
            'linkedin' => env('CLINIC_SITE_LINKEDIN'),
        ]),

        // The "clinic sign-in" link to the web app. Off while clinics work
        // from the mobile app only; switch on when the web app is in use.
        'show_login' => (bool) env('CLINIC_SITE_SHOW_LOGIN', false),

        // Real screens of the product, shown in phone frames. Replacing one is
        // a matter of dropping a new file in under the same name (portrait,
        // 780 × 1560 or the same 1:2 ratio).
        'screens' => [
            'doctor_page' => 'images/site/screen-doctor-page.jpg',
            'booking' => 'images/site/screen-booking.jpg',
            'tracking' => 'images/site/screen-tracking.jpg',
            'report' => 'images/site/screen-report.jpg',
        ],
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

        // The waiting page's time box. Off: the time the patient booked
        // ("موعد حجزك"). On: the estimate of when they go in, from the
        // visits still ahead — being refined on staging, so off by default
        // and switched on per environment once it is ready.
        'show_estimate' => (bool) env('CLINIC_TRACKING_SHOW_ESTIMATE', false),
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
    | The doctor's report: a page under /reports, and a WhatsApp message each
    | morning linking to it. One send time for every clinic — clinics do not
    | each get their own clock. Which clinics get it, and who may read it, are
    | per clinic and per account in the admin panel.
    */
    'reports' => [
        // The morning WhatsApp report. Paused until Meta approves the
        // clinic_report template; the reports page — on the web and in the
        // app — carries the numbers meanwhile. Pausing it never closes the
        // page: that is clinics.reports_enabled, a different switch.
        'morning_whatsapp' => (bool) env('CLINIC_REPORTS_MORNING_WHATSAPP', false),
        'send_at' => env('CLINIC_REPORTS_SEND_AT', '09:00'),
        'timezone' => env('CLINIC_REPORTS_TIMEZONE', 'Africa/Cairo'),
        // How long "remember me" keeps a doctor signed in to the reports page.
        'remember_days' => 90,

        // The mobile app opens the page in a WebView through a single-use
        // link that lasts this long. Opening it starts an ordinary session
        // (SESSION_LIFETIME, idle) rather than the 90-day remembered login.
        'app_link_ttl_seconds' => 300,
        // Set in the WebView when it first opens the page from the app, so a
        // lapsed session is sent to the renew address instead of the
        // password form. Holds no account details.
        'app_cookie' => 'reports_app',
        'app_cookie_days' => 365,
    ],

    /*
    | The clinic's QR poster: a printable A4 image whose code opens the
    | clinic's public page. Drawn fresh from the clinic every time — see
    | App\Services\V1\QrPoster\QrPosterService. The operator can adjust a
    | clinic's text and sizes in the panel; these are the defaults and the
    | limits those adjustments stay inside.
    */
    'qr_poster' => [
        // A4 at 300 dpi, so the downloaded PNG prints sharp at full size and
        // still reads on a phone.
        'width' => 2480,
        'height' => 3508,
        // Kept clear on both sides; nothing is drawn wider than what is left.
        'side_margin' => 180,

        // Font sizes and the QR tile's width, in poster pixels.
        'sizes' => [
            'name' => ['default' => 210, 'min' => 120, 'max' => 280],
            'title' => ['default' => 110, 'min' => 60, 'max' => 160],
            'qr' => ['default' => 1500, 'min' => 1000, 'max' => 1900],
        ],
        'call_to_action_size' => 135,

        // The specialty line wraps onto at most this many lines.
        'title_max_lines' => 3,

        // The background runs from `background` at the top to
        // `background_deep` at the bottom. `background` is the action blue of
        // the patient pages (--primary on the doctor page and the booking
        // flow), so the poster reads as the same product as the page it opens.
        'colors' => [
            'background' => '#185FA5',
            'background_deep' => '#0A2E55',
            // A soft sheen across the diagonal, and a glow behind the code.
            'sheen' => '#FFFFFF',
            'sheen_opacity' => 0.07,
            'glow_opacity' => 0.18,
            // The shadow under the code's white tile.
            'shadow_opacity' => 0.18,
            'text' => '#FFFFFF',
            'tile' => '#FFFFFF',
            // Dark, so the code keeps a strong contrast on its white tile.
            'modules' => '#0B3766',
        ],

        // High enough that a scuffed or partly covered print still scans.
        // One of L, M, Q, H.
        'error_correction' => 'Q',

        // The query string the code adds to the page address, so a visit from
        // the poster can be told apart from any other.
        'tracking' => [
            'param' => 'src',
            'value' => 'qr',
            // Link previews and crawlers fetch the address too; they are not
            // people standing in front of the poster.
            'ignored_user_agents' => [
                'bot', 'crawler', 'spider', 'preview', 'facebookexternalhit',
                'whatsapp', 'telegrambot', 'slackbot', 'twitterbot',
            ],
        ],
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

        // The pilot clinic, listed on the handoff page by name and email
        // only: it takes real patients, so its password is never printed.
        'pilot_account' => env('CLINIC_DOCS_PILOT_EMAIL', 'drseham@gmail.com'),

        // The code in front of /handoff. Set per server in .env, never here:
        // this repository is public. Unset on a server means the page stays
        // closed; unset locally means open, for development.
        'handoff_code' => env('HANDOFF_ACCESS_CODE'),

        // How long a browser that entered the code is remembered.
        'handoff_remember_days' => 30,

        // The live site, named on the handoff page whichever host serves it.
        'production_url' => env('CLINIC_DOCS_PRODUCTION_URL', 'https://elayadah.com'),

        // Where the team tests before production — see docs/deploy/staging.md.
        'staging_url' => env('CLINIC_DOCS_STAGING_URL', 'https://staging.elayadah.com'),
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
