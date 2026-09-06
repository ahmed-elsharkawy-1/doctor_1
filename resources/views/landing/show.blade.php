@php
    $title = ($doctor?->name ?? $clinic->name).' — '.$clinic->specialty?->name;
    $description = __('landing.meta_description', [
        'doctor' => $doctor?->name ?? $clinic->name,
        'specialty' => $clinic->specialty?->name,
        'address' => $clinic->address ?: $clinic->name,
    ]);

    $greeting = __('landing.whatsapp_greeting', ['clinic' => $clinic->name]);
    $waLink = $whatsapp === null
        ? null
        : 'https://wa.me/'.$whatsapp.'?text='.rawurlencode($greeting);

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
        'address' => $clinic->address === null ? null : [
            '@type' => 'PostalAddress',
            'streetAddress' => $clinic->address,
        ],
        'medicalSpecialty' => $clinic->specialty?->name_en,
        'openingHours' => $hours,
        'employee' => $doctor === null ? null : [
            '@type' => 'Physician',
            'name' => $doctor->name,
            'medicalSpecialty' => $clinic->specialty?->name_en,
        ],
    ]);

    $schemaJson = json_encode(
        $schema,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT,
    );
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
    <meta name="twitter:card" content="summary">

    {{-- Structured data, so the clinic can surface as a place rather than a page. --}}
    <script type="application/ld+json">{!! $schemaJson !!}</script>

    <style>
        :root {
            --bg: #eef2f7;
            --card: #ffffff;
            --ink: #16202e;
            --muted: #6b7a8d;
            --line: #e2e8f0;
            --brand: #0f766e;
            --brand-soft: #e6f4f1;
            --wa: #25d366;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, system-ui, sans-serif;
            line-height: 1.7;
        }

        .wrap { max-width: 34rem; margin: 0 auto; padding: 1rem 1rem 5rem; }

        .hero {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 1.75rem 1.25rem;
            text-align: center;
            margin: 1.5rem 0 0.85rem;
        }

        .badge {
            display: inline-block;
            background: var(--brand-soft);
            color: var(--brand);
            border-radius: 999px;
            padding: 0.2rem 0.85rem;
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 0.6rem;
        }

        .hero h1 { margin: 0 0 0.15rem; font-size: 1.5rem; }
        .hero .clinic { color: var(--muted); margin: 0; }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 1.1rem 1.25rem;
            margin-bottom: 0.85rem;
        }

        .card h2 {
            margin: 0 0 0.6rem;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--muted);
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.45rem 0;
            border-bottom: 1px solid var(--line);
        }

        .row:last-child { border-bottom: 0; }
        .row .v { font-weight: 600; }

        .cta {
            display: block;
            background: var(--wa);
            color: #06301a;
            text-align: center;
            text-decoration: none;
            font-weight: 800;
            font-size: 1.05rem;
            padding: 1rem;
            border-radius: 0.9rem;
            margin-bottom: 0.85rem;
        }

        .cta-secondary {
            display: block;
            text-align: center;
            text-decoration: none;
            font-weight: 700;
            padding: 0.85rem;
            border-radius: 0.9rem;
            border: 1px solid var(--line);
            background: var(--card);
            color: var(--ink);
        }

        .types { display: flex; flex-wrap: wrap; gap: 0.4rem; }

        .type {
            border: 1px solid var(--line);
            border-radius: 0.6rem;
            padding: 0.3rem 0.7rem;
            font-size: 0.9rem;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1620;
                --card: #18222f;
                --ink: #e8eef5;
                --muted: #93a3b6;
                --line: #26333f;
                --brand: #4db6a5;
                --brand-soft: #16302c;
            }

            .cta { color: #04240f; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="hero">
        @if ($clinic->specialty)
            <div class="badge">{{ $clinic->specialty->name }}</div>
        @endif

        <h1>{{ $doctor?->name ?? $clinic->name }}</h1>
        @if ($doctor)
            <p class="clinic">{{ $clinic->name }}</p>
        @endif
    </div>

    @if ($waLink)
        <a class="cta" href="{{ $waLink }}">{{ __('landing.book_on_whatsapp') }}</a>
    @endif

    @if ($phone)
        <a class="cta-secondary" href="tel:{{ $phone }}" style="margin-bottom:0.85rem">
            {{ __('landing.call') }} — <span dir="ltr">{{ $phone->national() }}</span>
        </a>
    @endif

    @if ($clinic->address)
        <div class="card">
            <h2>{{ __('landing.address') }}</h2>
            <div>{{ $clinic->address }}</div>
            <a href="https://maps.google.com/?q={{ urlencode($clinic->address) }}"
               style="color:var(--brand);font-weight:600">{{ __('landing.directions') }}</a>
        </div>
    @endif

    @if ($openDays->isNotEmpty())
        <div class="card">
            <h2>{{ __('landing.hours') }}</h2>
            @foreach ($openDays as $schedule)
                <div class="row">
                    <span>{{ $schedule->day_of_week->label() }}</span>
                    <span class="v" dir="ltr">
                        @foreach ($schedule->periods as $period)
                            {{ $period->startTime() }}–{{ $period->endTime() }}@if (! $loop->last), @endif
                        @endforeach
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    @if ($clinic->visitTypes->isNotEmpty())
        <div class="card">
            <h2>{{ __('landing.services') }}</h2>
            <div class="types">
                @foreach ($clinic->visitTypes as $visitType)
                    <span class="type">{{ $visitType->name }}</span>
                @endforeach
            </div>
        </div>
    @endif

</div>
</body>
</html>
