@php
    $brand = config('clinic.brand.name');
    $isArabic = app()->getLocale() === 'ar';
    $sections = trans('privacy.sections');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('privacy.meta.title', ['brand' => $brand]) }}</title>
    <meta name="description" content="{{ __('privacy.meta.description', ['brand' => $brand]) }}">
    <link rel="canonical" href="{{ route('privacy') }}">
    <link rel="alternate" hreflang="ar" href="{{ route('privacy') }}">
    <link rel="alternate" hreflang="en" href="{{ route('privacy', ['lang' => 'en']) }}">
    @include('partials.brand-head')

    <style>
        @font-face { font-family: Tajawal; font-weight: 500; font-display: swap; src: url("{{ asset('fonts/tajawal/tajawal-500-arabic.woff2') }}") format("woff2"); unicode-range: U+0600-06FF, U+0750-077F, U+0870-089F, U+08A0-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FEFF; }
        @font-face { font-family: Tajawal; font-weight: 500; font-display: swap; src: url("{{ asset('fonts/tajawal/tajawal-500-latin.woff2') }}") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
        @font-face { font-family: Tajawal; font-weight: 800; font-display: swap; src: url("{{ asset('fonts/tajawal/tajawal-800-arabic.woff2') }}") format("woff2"); unicode-range: U+0600-06FF, U+0750-077F, U+0870-089F, U+08A0-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FEFF; }
        @font-face { font-family: Tajawal; font-weight: 800; font-display: swap; src: url("{{ asset('fonts/tajawal/tajawal-800-latin.woff2') }}") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }

        /* Light only, like every other public page. */
        :root {
            color-scheme: light;
            --brand: {{ config('clinic.brand.color') }};
            --brand-ink: #0A2540;
            --brand-50: #EEF6FE;
            --ink: #0F1E2E;
            --ink-soft: #33475A;
            --muted: #5F6F80;
            --line: #E3EAF2;
            --surface: #FFFFFF;
            --surface-2: #F5F8FC;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--surface-2);
            color: var(--ink);
            font-family: Tajawal, "Segoe UI", Tahoma, system-ui, sans-serif;
            font-weight: 500;
            font-size: 16px;
            line-height: 1.8;
            -webkit-text-size-adjust: 100%;
        }

        a { color: var(--brand); }

        .page { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }

        .top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        .logo { display: inline-flex; align-items: center; gap: 10px; color: var(--brand-ink); font-weight: 800; text-decoration: none; }
        .logo img { border-radius: 10px; }
        .lang { display: inline-flex; align-items: center; min-height: 44px; padding: 0 14px; border: 1px solid var(--line); border-radius: 999px; background: var(--surface); color: var(--ink-soft); text-decoration: none; font-size: 14px; }
        .lang:hover { border-color: var(--brand); color: var(--brand); }

        article { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 28px 20px; }
        @media (min-width: 640px) { article { padding: 40px; } }

        h1 { margin: 0; font-size: 28px; font-weight: 800; line-height: 1.3; color: var(--brand-ink); }
        .updated { margin: 6px 0 0; color: var(--muted); font-size: 14px; }
        .intro { margin: 20px 0 0; color: var(--ink-soft); }

        section { margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--line); }
        h2 { margin: 0 0 8px; font-size: 19px; font-weight: 800; color: var(--brand-ink); }
        section p { margin: 0 0 8px; color: var(--ink-soft); }
        ul { margin: 0; padding-inline-start: 22px; color: var(--ink-soft); }
        li + li { margin-top: 6px; }
        li::marker { color: var(--brand); }

        .contact { margin-top: 28px; padding: 20px; border-radius: 12px; background: var(--brand-50); }
        .contact h2 { margin-bottom: 4px; }
        .contact ul { list-style: none; padding: 0; margin-top: 8px; }
        .contact li { display: flex; flex-wrap: wrap; gap: 4px 8px; }
        .contact strong { color: var(--brand-ink); }
        /* Numbers and addresses read left to right even on the Arabic page. */
        .ltr { direction: ltr; unicode-bidi: isolate; }

        footer { margin-top: 20px; text-align: center; color: var(--muted); font-size: 14px; }
        footer a { color: var(--muted); }
        .legal { margin-top: 12px; font-size: 13px; }
        .legal p { margin: 4px 0 0; }
    </style>
</head>
<body>
@include('partials.test-site-banner')

<div class="page">
    <header class="top">
        <a class="logo" href="{{ route('root') }}">
            <img src="{{ asset(config('clinic.brand.logo')) }}" alt="" width="36" height="36">
            <span>{{ $brand }}</span>
        </a>
        <a class="lang" href="{{ $isArabic ? route('privacy', ['lang' => 'en']) : route('privacy') }}" hreflang="{{ $isArabic ? 'en' : 'ar' }}" lang="{{ $isArabic ? 'en' : 'ar' }}">
            {{ __('privacy.switch_language') }}
        </a>
    </header>

    <article>
        <h1>{{ __('privacy.title') }}</h1>
        <p class="updated">{{ __('privacy.updated') }}</p>
        <p class="intro">{{ __('privacy.intro', ['brand' => $brand]) }}</p>
        <p class="intro">{{ __('privacy.operator', ['name' => config('clinic.legal.name'), 'name_ar' => config('clinic.legal.name_ar')]) }}</p>

        @foreach ($sections as $key => $section)
            <section id="{{ str_replace('_', '-', $key) }}">
                <h2>{{ $section['title'] }}</h2>
                @isset($section['lead'])<p>{{ $section['lead'] }}</p>@endisset
                <ul>
                    @foreach ($section['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
                @isset($section['how'])<p style="margin-top: 10px;">{{ $section['how'] }}</p>@endisset
            </section>
        @endforeach

        @if ($email || $whatsappLink || $phone)
            <div class="contact" id="contact">
                <h2>{{ __('privacy.contact.title') }}</h2>
                <p>{{ __('privacy.contact.lead') }}</p>
                <ul>
                    @if ($email)
                        <li><strong>{{ __('privacy.contact.email') }}:</strong> <a class="ltr" href="mailto:{{ $email }}">{{ $email }}</a></li>
                    @endif
                    @if ($whatsappLink)
                        <li><strong>{{ __('privacy.contact.whatsapp') }}:</strong> <a class="ltr" href="{{ $whatsappLink }}" target="_blank" rel="noopener">{{ $whatsappNational }}</a></li>
                    @endif
                    @if ($phone)
                        <li><strong>{{ __('privacy.contact.phone') }}:</strong> <a class="ltr" href="tel:{{ $phone->e164 }}">{{ $phone->national() }}</a></li>
                    @endif
                </ul>
            </div>
        @endif
    </article>

    <footer>
        <a href="{{ route('root') }}">{{ __('privacy.back_home') }}</a>
        · © {{ now()->year }} {{ $brand }}
        @include('partials.legal-footer')
    </footer>
</div>
</body>
</html>
