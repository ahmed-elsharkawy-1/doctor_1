@php
    use App\Enums\DayOfWeek;

    $doctorName = $doctor?->name ?? $clinic->name;
    $title = $doctorName.' — '.($doctor?->title ?: $clinic->specialty?->name);

    $description = __('landing.meta_description', [
        'doctor' => $doctorName,
        'specialty' => $doctor?->title ?: $clinic->specialty?->name,
        'address' => $clinic->address ?: $clinic->name,
    ]);

    $greeting = __('landing.whatsapp_greeting', ['clinic' => $clinic->name]);
    $waLink = $whatsapp === null
        ? null
        : 'https://wa.me/'.$whatsapp.'?text='.rawurlencode($greeting);

    $mapQuery = $clinic->latitude !== null && $clinic->longitude !== null
        ? $clinic->latitude.','.$clinic->longitude
        : $clinic->address;
    $mapLink = $mapQuery === null ? null : 'https://maps.google.com/?q='.urlencode($mapQuery);

    // Schema.org opening hours want 2-letter day codes.
    $schemaDays = [0 => 'Sa', 1 => 'Su', 2 => 'Mo', 3 => 'Tu', 4 => 'We', 5 => 'Th', 6 => 'Fr'];

    $hours = [];
    foreach ($openDays as $schedule) {
        foreach ($schedule->periods as $period) {
            $hours[] = ($schemaDays[$schedule->day_of_week->value] ?? 'Mo')
                .' '.$period->startTime().'-'.$period->endTime();
        }
    }

    $schema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'MedicalClinic',
        'name' => $clinic->name,
        'url' => url()->current(),
        'telephone' => $phone === null ? null : (string) $phone,
        'image' => $doctor?->photoUrl(),
        'address' => $clinic->address === null ? null : array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $clinic->address,
            'addressLocality' => $clinic->city,
        ]),
        'geo' => $clinic->latitude === null ? null : [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $clinic->latitude,
            'longitude' => (float) $clinic->longitude,
        ],
        'medicalSpecialty' => $clinic->specialty?->name_en,
        'openingHours' => $hours,
        'employee' => $doctor === null ? null : array_filter([
            '@type' => 'Physician',
            'name' => $doctor->name,
            'jobTitle' => $doctor->title,
            'medicalSpecialty' => $clinic->specialty?->name_en,
        ]),
    ]);

    // HEX flags matter: the clinic name is operator-entered and this is
    // injected raw into a <script> block.
    $schemaJson = json_encode(
        $schema,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT,
    );

    $initials = mb_substr(trim(preg_replace('/^د\.\s*/u', '', $doctorName)), 0, 1);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    {{-- A real portrait shares better than the stock avatar; failing that the
         platform cover, which at least carries the name and the promise. --}}
    @include('partials.brand-head', ['shareImage' => $doctor?->photoUrl()])
    <meta name="twitter:card" content="summary">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    {{-- Structured data, so the clinic can surface as a place rather than a page. --}}
    <script type="application/ld+json">{!! $schemaJson !!}</script>

    <style>
        /* Tokens read from the Figma file (Public/*). Light only in V1. */
        :root {
            color-scheme: light;
            --ink: #132433;
            --ink-soft: #33475A;
            --muted: #5F6F80;
            --faint: #8B9AAA;
            --primary: #185FA5;
            --primary-50: #EEF4FB;
            --primary-100: #D8E8F7;
            --whatsapp: #1FAF54;
            --success-bg: #E7F4EC;
            --surface: #FFFFFF;
            --surface-2: #F6F9FC;
            --line: #E5ECF3;
            --line-strong: #CFDAE6;
            --bg: #EEF2F7;
            --shadow: 0 14px 28px rgba(20, 60, 100, .10);
            --shadow-sm: 0 4px 8px rgba(20, 60, 100, .06);
            --radius: 16px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: Tajawal, "Segoe UI", Tahoma, system-ui, sans-serif;
            font-size: 15px;
            line-height: 1.6;
            -webkit-text-size-adjust: 100%;
        }

        h1, h2, h3, p { margin: 0; }
        a { color: inherit; text-decoration: none; }

        .shell { width: min(1000px, 100% - 32px); margin: 0 auto; }

        /* ---------- Banner ---------- */
        .banner {
            position: relative;
            background:
                radial-gradient(120% 140% at 85% 0%, #2C7FD0 0%, transparent 55%),
                linear-gradient(200deg, #1B6BB5 0%, #124C86 55%, #0E3E6E 100%);
            background-color: #124C86;
            padding: 18px 0 96px;
        }

        .banner-bar {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            font-weight: 800;
            font-size: 17px;
        }

        /* The mark is knocked out in white, so it needs no tile behind it —
           the banner's own blue is what makes it read. */
        .brand .mark {
            height: 26px;
            width: auto;
            display: block;
        }

        /* ---------- Doctor header ---------- */
        .profile {
            position: relative;
            margin-top: -80px;
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 22px;
        }

        .identity {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            justify-content: space-between;
        }

        .identity h1 { font-size: 26px; font-weight: 800; line-height: 1.25; }
        .identity .role {
            margin-top: 6px;
            color: var(--primary);
            font-size: 16px;
            font-weight: 700;
            line-height: 1.45;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 8px;
            color: var(--muted);
            font-size: 13.5px;
        }

        .meta span { display: inline-flex; align-items: center; gap: 5px; }

        .portrait {
            flex: 0 0 auto;
            width: 96px; height: 96px;
            margin-top: -58px;
            border-radius: 20px;
            border: 4px solid var(--surface);
            box-shadow: var(--shadow-sm);
            object-fit: cover;
            background: var(--primary-50);
        }

        .portrait-fallback {
            display: grid;
            place-items: center;
            color: var(--primary);
            font-size: 34px;
            font-weight: 800;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 18px;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
        }

        .stat .ico {
            display: grid;
            place-items: center;
            width: 30px; height: 30px;
            flex: 0 0 auto;
            border-radius: 9px;
            background: var(--primary-50);
            color: var(--primary);
        }

        .stat .k { display: block; color: var(--faint); font-size: 12px; }
        .stat .v { display: block; font-weight: 700; font-size: 14px; }

        .cta-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 10px 16px;
            border-radius: 12px;
            border: 1px solid transparent;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-wa { background: var(--whatsapp); color: #fff; }
        .btn-wa:hover { filter: brightness(.95); }
        .btn-ghost { background: var(--surface); border-color: var(--line-strong); color: var(--ink); }
        .btn-ghost:hover { background: var(--surface-2); }

        /* ---------- Tabs ---------- */
        .tabs-wrap {
            position: sticky;
            top: 0;
            z-index: 5;
            margin-top: 12px;
            padding: 8px 0;
            background: var(--bg);
            --fade: 30px;
        }

        /*
           A horizontally scrollable strip that looks exactly like a full one is
           a trap: the last tabs simply do not exist as far as the reader is
           concerned. These fades appear only on the side that has more to show
           and vanish once you reach that end, so the page is telling the truth
           at every scroll position rather than decorating an edge for ever.

           The document is always dir="rtl", so inline-start is the right edge.
           Physical properties are used deliberately — a logical gradient
           direction is not something every target browser agrees on.
        */
        .tabs-wrap::before,
        .tabs-wrap::after {
            content: "";
            position: absolute;
            top: 8px;
            bottom: 8px;
            width: var(--fade);
            pointer-events: none;
            opacity: 0;
            transition: opacity .18s ease;
            z-index: 2;
        }

        .tabs-wrap::before {
            right: 0;
            border-radius: 0 14px 14px 0;
            background: linear-gradient(to left, var(--surface), rgba(255, 255, 255, 0));
        }

        .tabs-wrap::after {
            left: 0;
            border-radius: 14px 0 0 14px;
            background: linear-gradient(to right, var(--surface), rgba(255, 255, 255, 0));
        }

        .tabs-wrap[data-scroll~="start"]::before { opacity: 1; }
        .tabs-wrap[data-scroll~="end"]::after { opacity: 1; }

        /* One gentle shove on first sight, so the strip is seen to move rather
           than merely hinted at. It runs once and never fights the reader. */
        @media (prefers-reduced-motion: no-preference) {
            .tabs.is-nudging { animation: tab-nudge .9s ease-in-out; }
        }

        @keyframes tab-nudge {
            0%, 100% { transform: translateX(0); }
            35% { transform: translateX(14px); }
            70% { transform: translateX(-4px); }
        }

        .tabs {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            scrollbar-width: none;
            scroll-behavior: smooth;
            background: var(--surface);
            border-radius: 14px;
            box-shadow: var(--shadow-sm);
            padding: 8px;
        }

        .tabs::-webkit-scrollbar { display: none; }

        .tab {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            border: 0;
            border-radius: 10px;
            background: none;
            padding: 9px 14px;
            font: inherit;
            font-weight: 700;
            color: var(--muted);
            cursor: pointer;
        }

        .tab[aria-selected="true"] { background: var(--primary-50); color: var(--primary); }

        /* ---------- Layout ---------- */
        .panel[hidden] { display: none; }

        .cols {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 336px;
            gap: 16px;
            align-items: start;
            padding-bottom: 40px;
        }

        .stack { display: grid; gap: 16px; }

        .card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 20px;
        }

        .card > h2 { font-size: 18px; font-weight: 800; margin-bottom: 14px; }

        .card-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .card-head h2 { font-size: 18px; font-weight: 800; }
        .card-head a { color: var(--primary); font-weight: 700; font-size: 13.5px; }

        .lede { color: var(--ink-soft); }

        /* Booking sidebar */
        .booking { border-top: 3px solid var(--whatsapp); }
        .booking .lede { color: var(--muted); font-size: 13.5px; margin-bottom: 14px; }

        .steps { display: grid; gap: 12px; margin-bottom: 16px; }

        .step { display: grid; grid-template-columns: 22px minmax(0, 1fr); gap: 10px; }

        .step .n {
            display: grid;
            place-items: center;
            width: 20px; height: 20px;
            border-radius: 999px;
            background: var(--primary-50);
            color: var(--primary);
            font-size: 11px;
            font-weight: 800;
        }

        .step b { font-size: 14px; }
        .step small { color: var(--muted); display: block; }

        .booking .btn { width: 100%; margin-bottom: 8px; }

        .note {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-top: 6px;
            padding-top: 12px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 12.5px;
        }

        .note .tick {
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            width: 20px; height: 20px;
            border-radius: 999px;
            background: var(--success-bg);
            color: #147F3C;
        }

        /* Lists */
        .rows { display: grid; }

        .row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 0;
            border-top: 1px solid var(--line);
        }

        .row:first-child { border-top: 0; padding-top: 0; }

        .row .ico {
            display: grid;
            place-items: center;
            width: 30px; height: 30px;
            flex: 0 0 auto;
            border-radius: 9px;
            background: var(--primary-50);
            color: var(--primary);
        }

        .row b { display: block; font-size: 14.5px; }
        .row small { color: var(--muted); }
        .row .grow { flex: 1 1 auto; min-width: 0; }

        .badge {
            flex: 0 0 auto;
            border-radius: 10px;
            background: var(--primary-50);
            color: var(--primary);
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .service {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 8px;
        }

        .service:last-of-type { margin-bottom: 0; }

        /* Services as blocks. auto-fit lands on four across for a clinic with
           four services, and still reads well for three or six. */
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
            gap: 10px;
        }

        .service-block {
            display: grid;
            place-items: center;
            text-align: center;
            min-height: 64px;
            padding: 12px 10px;
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: 12px;
            font-weight: 700;
            font-size: 14.5px;
        }

        @media (max-width: 560px) {
            .service-grid { grid-template-columns: 1fr 1fr; }
        }

        .hint {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-top: 12px;
            background: var(--primary-50);
            border-radius: 12px;
            padding: 11px 13px;
            color: var(--ink-soft);
            font-size: 13px;
        }

        /* Hours */
        .hour {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--line);
        }

        .hour:last-of-type { border-bottom: 0; }
        .hour .day { font-weight: 600; }
        .hour.is-today .day { color: var(--primary); font-weight: 800; }

        .today-pill {
            margin-inline-start: 6px;
            border-radius: 999px;
            background: var(--primary);
            color: #fff;
            padding: 1px 8px;
            font-size: 11px;
            font-weight: 700;
        }

        .hour .times {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 6px;
        }

        .time-tag {
            border-radius: 8px;
            background: var(--primary-50);
            color: var(--primary);
            padding: 4px 10px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .hour.is-today .time-tag { background: var(--primary); color: #fff; }

        .hour .off {
            color: var(--faint);
            background: var(--surface-2);
            border-radius: 8px;
            padding: 4px 10px;
            font-size: 13px;
        }

        /* Gallery */
        .gallery { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        .shot {
            position: relative;
            display: block;
            border-radius: 12px;
            overflow: hidden;
            aspect-ratio: 16 / 10;
            background: var(--surface-2);
        }

        .shot img { width: 100%; height: 100%; object-fit: cover; display: block; }

        .shot figcaption {
            position: absolute;
            inset-inline-start: 10px;
            bottom: 8px;
            color: #fff;
            font-size: 12.5px;
            font-weight: 700;
            text-shadow: 0 1px 4px rgba(0, 0, 0, .6);
        }

        .map {
            width: 100%;
            aspect-ratio: 16 / 9;
            border: 0;
            border-radius: 12px;
            background: var(--surface-2);
        }

        /* Sticky mobile CTA */
        .dock { display: none; }

        @media (max-width: 900px) {
            .cols { grid-template-columns: minmax(0, 1fr); }
            .stats { grid-template-columns: 1fr 1fr; }
            .tabs-wrap { --fade: 38px; }
            .cta-row { grid-template-columns: 1fr; }
            .identity h1 { font-size: 22px; }
            .banner { padding-bottom: 88px; }

            /* The sidebar reads as the last thing on a phone, not the first. */
            .side { order: 2; }

            .dock {
                position: fixed;
                inset-inline: 0;
                bottom: 0;
                z-index: 20;
                display: flex;
                gap: 8px;
                padding: 10px 16px calc(10px + env(safe-area-inset-bottom));
                background: rgba(255, 255, 255, .96);
                border-top: 1px solid var(--line);
                backdrop-filter: blur(8px);
            }

            .dock .btn-wa { flex: 1 1 auto; }
            .dock .btn-ghost { flex: 0 0 auto; }
            body { padding-bottom: 78px; }
        }

        /* A phone reads the three facts as a list. Two-up strands the third on
           a line of its own and squeezes the specialty into four lines. */
        @media (max-width: 700px) {
            .stats { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>
<body>

<header class="banner">
    <div class="shell banner-bar">
        <div class="brand">
            <img class="mark" src="{{ asset(config('clinic.brand.logo_white')) }}"
                 alt="{{ __('landing.brand') }}" width="55" height="26">
            {{ __('landing.brand') }}
        </div>
    </div>
</header>

<main class="shell">

    <section class="profile">
        <div class="identity">
            <div>
                <h1>{{ $doctorName }}</h1>

                @if ($doctor?->title || $clinic->specialty)
                    <p class="role">{{ $doctor?->title ?: $clinic->specialty?->name }}</p>
                @endif

            </div>

            @if ($doctor?->avatarUrl())
                <img class="portrait" src="{{ $doctor->avatarUrl() }}" alt="{{ $doctorName }}" loading="lazy">
            @else
                <div class="portrait portrait-fallback" aria-hidden="true">{{ $initials }}</div>
            @endif
        </div>

        <div class="stats">
            @include('landing.partials.stat', [
                'label' => __('landing.stat_specialty'),
                'value' => $doctor?->title ?: $clinic->specialty?->name,
                'icon' => 'stethoscope',
            ])
            @include('landing.partials.stat', [
                'label' => __('landing.stat_working_days'),
                'value' => $workingDaysLabel,
                'icon' => 'calendar',
            ])
            @include('landing.partials.stat', [
                'label' => __('landing.stat_location'),
                'value' => $clinic->city ?: $clinic->address,
                'icon' => 'pin',
            ])
        </div>

        <div class="cta-row">
            @if ($waLink)
                <a class="btn btn-wa" href="{{ $waLink }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm5.6 14.1c-.2.6-1.2 1.2-1.7 1.2-.4 0-.9.2-3.1-.7-2.6-1.1-4.2-3.8-4.3-4-.1-.2-1-1.4-1-2.6 0-1.2.6-1.8.9-2 .2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.3.3c-.1.1-.3.3-.1.6.1.3.7 1.2 1.5 1.9 1 .9 1.8 1.2 2.1 1.3.2.1.4.1.6-.1l.8-1c.2-.2.3-.2.5-.1l2 1c.2.1.4.2.4.3.1.1.1.6-.1 1.2Z"/></svg>
                    {{ __('landing.book_on_whatsapp') }}
                </a>
            @endif

            @if ($phone)
                <a class="btn btn-ghost" href="tel:{{ $phone }}">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.9v2a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 3.2 2 2 0 0 1 4.1 1h2a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L7.1 8.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg>
                    {{ __('landing.call') }}
                </a>
            @endif
        </div>
    </section>

    <div class="tabs-wrap">
        <div class="tabs" role="tablist">
            @foreach (['overview', 'services', 'contact', 'location'] as $tab)
                <button type="button" class="tab" role="tab"
                        id="tab-{{ $tab }}" aria-controls="panel-{{ $tab }}"
                        aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                    @include('landing.partials.icon', ['name' => ['overview' => 'user', 'services' => 'calendar', 'contact' => 'phone', 'location' => 'pin'][$tab]])
                    {{ __('landing.tab_'.$tab) }}
                </button>
            @endforeach
        </div>
    </div>

    @include('landing.partials.tab-overview')
    @include('landing.partials.tab-services')
    @include('landing.partials.tab-contact')
    @include('landing.partials.tab-location')

</main>

@if ($waLink)
    <nav class="dock">
        <a class="btn btn-wa" href="{{ $waLink }}">{{ __('landing.book_on_whatsapp') }}</a>
        @if ($phone)
            <a class="btn btn-ghost" href="tel:{{ $phone }}" aria-label="{{ __('landing.call') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.9v2a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 3.2 2 2 0 0 1 4.1 1h2a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L7.1 8.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg>
            </a>
        @endif
    </nav>
@endif

<script>
    // Tabs. The chosen one lives in the URL hash so a link can open on it.
    const tabs = [...document.querySelectorAll('.tab')];

    function show(id, push) {
        tabs.forEach(tab => {
            const on = tab.id === id;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !on;
        });
        if (push) { history.replaceState(null, '', '#' + id.replace('tab-', '')); }

        document.getElementById(id)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    tabs.forEach(tab => tab.addEventListener('click', () => show(tab.id, true)));

    /*
       Tell the reader which way the strip still has tabs to give.

       In RTL scrollLeft counts down from zero, so both ends are measured
       against its magnitude rather than its sign — that is the one part of
       this browsers genuinely disagree about.
    */
    const strip = document.querySelector('.tabs');
    const stripWrap = document.querySelector('.tabs-wrap');

    function markOverflow() {
        if (!strip || !stripWrap) { return; }

        const offset = Math.abs(strip.scrollLeft);
        const hidden = strip.scrollWidth - strip.clientWidth;
        const sides = [];

        if (hidden > 1) {
            if (offset > 1) { sides.push('start'); }
            if (offset < hidden - 1) { sides.push('end'); }
        }

        stripWrap.setAttribute('data-scroll', sides.join(' '));

        return hidden > 1;
    }

    if (strip) {
        strip.addEventListener('scroll', markOverflow, { passive: true });
        window.addEventListener('resize', markOverflow);

        // The one-time shove, once the strip is actually on screen and only if
        // there is something off it to find.
        if (markOverflow() && 'IntersectionObserver' in window) {
            const nudge = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) { return; }

                    strip.classList.add('is-nudging');
                    strip.addEventListener('animationend', () => strip.classList.remove('is-nudging'), { once: true });
                    nudge.disconnect();
                });
            });

            nudge.observe(strip);
        }
    }

    const fromHash = 'tab-' + location.hash.replace('#', '');
    if (location.hash && document.getElementById(fromHash)) { show(fromHash, false); }

    // Anything linking to another tab, such as "all services".
    document.querySelectorAll('[data-tab]').forEach(link => {
        link.addEventListener('click', event => {
            event.preventDefault();
            show('tab-' + link.dataset.tab, true);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
</script>
</body>
</html>
