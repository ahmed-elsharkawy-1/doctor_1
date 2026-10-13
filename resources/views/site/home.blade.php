@php
    $brand = config('clinic.brand.name');
    $title = __('site.meta.title', ['brand' => $brand]);
    $description = __('site.meta.description');
    $showLogin = (bool) config('clinic.site.show_login');

    // Outline icons, 24px grid, drawn with currentColor.
    $icons = [
        'calendar' => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 3v3M16 3v3M8 14h3"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.7 3.9 5.7 3.9 9s-1.3 6.3-3.9 9c-2.6-2.7-3.9-5.7-3.9-9S9.4 5.7 12 3Z"/>',
        'queue' => '<circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="7" r="2.5"/><path d="M3 19c0-2.5 1.8-4.5 4-4.5s4 2 4 4.5M13 19c0-2.5 1.8-4.5 4-4.5s4 2 4 4.5"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
        'phone' => '<path d="M5 4h3.5l1.5 4-2 1.5a11 11 0 0 0 6.5 6.5l1.5-2 4 1.5V19a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
        'book' => '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v15H6.5A1.5 1.5 0 0 0 5 19.5Z"/><path d="M5 19.5A1.5 1.5 0 0 0 6.5 21H19v-3M9 7.5h6"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.3c2 .8 3.5 2.8 3.5 5.7"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'chat' => '<path d="M20 12a8 8 0 0 1-11.7 7.1L4 20l1-4.1A8 8 0 1 1 20 12Z"/><path d="M8.5 12h.01M12 12h.01M15.5 12h.01"/>',
        'copy' => '<rect x="8.5" y="8.5" width="12" height="12" rx="2.5"/><path d="M15.5 8.5V6A2.5 2.5 0 0 0 13 3.5H6A2.5 2.5 0 0 0 3.5 6v7A2.5 2.5 0 0 0 6 15.5h2.5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
        'arrow' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'login' => '<path d="M14 4h3.5A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5H14M10 8l-4 4 4 4M6 12h10"/>',
        'patient' => '<circle cx="12" cy="7.5" r="3.5"/><path d="M5 20.5c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/>',
    ];

    $icon = fn (string $name, int $size = 22): string => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$icons[$name].'</svg>';

    $waIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2l-.4-.3Z"/></svg>';

    // Per pair: the problem's icon, then the solution's.
    $pairIcons = [['phone', 'globe'], ['book', 'calendar'], ['users', 'queue'], ['clock', 'chart']];
    $roleIcons = ['doctor' => 'chart', 'secretary' => 'calendar', 'patient' => 'patient'];

    $nav = ['features', 'roles', 'screens', 'faq'];

    $socialLabels = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn'];

@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ route('root') }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ route('root') }}">
    <meta property="og:locale" content="ar_EG">
    <meta name="twitter:card" content="summary_large_image">
    @include('partials.brand-head')

    <link rel="preload" href="{{ asset('fonts/tajawal/tajawal-800-arabic.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/tajawal/tajawal-500-arabic.woff2') }}" as="font" type="font/woff2" crossorigin>

    <link rel="preload" href="{{ asset(config('clinic.brand.logo_white')) }}" as="image">

    <script>
        document.documentElement.classList.add('js');
        // The opening screen plays once per visit, not on every return.
        try { if (sessionStorage.getItem('splash-seen')) { document.documentElement.classList.add('no-splash'); } } catch (e) {}
    </script>

    <style>
        @font-face { font-family: Tajawal; font-weight: 500; font-display: swap; src: url("{{ asset('fonts/tajawal/tajawal-500-arabic.woff2') }}") format("woff2"); unicode-range: U+0600-06FF, U+0750-077F, U+0870-089F, U+08A0-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FEFF; }
        @font-face { font-family: Tajawal; font-weight: 500; font-display: swap; src: url("{{ asset('fonts/tajawal/tajawal-500-latin.woff2') }}") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
        @font-face { font-family: Tajawal; font-weight: 800; font-display: swap; src: url("{{ asset('fonts/tajawal/tajawal-800-arabic.woff2') }}") format("woff2"); unicode-range: U+0600-06FF, U+0750-077F, U+0870-089F, U+08A0-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FEFF; }
        @font-face { font-family: Tajawal; font-weight: 800; font-display: swap; src: url("{{ asset('fonts/tajawal/tajawal-800-latin.woff2') }}") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }

        /* Light only, like every other public page. */
        :root {
            color-scheme: light;
            --brand: {{ config('clinic.brand.color') }};
            --brand-deep: #0B4F94;
            --brand-ink: #0A2540;
            --brand-50: #EEF6FE;
            --brand-100: #D9EBFC;
            --ink: #0F1E2E;
            --ink-soft: #33475A;
            --muted: #5F6F80;
            --line: #E3EAF2;
            --surface: #FFFFFF;
            --surface-2: #F5F8FC;
            --whatsapp: #1FAF54;
            --danger: #C2410C;
            --shadow: 0 24px 48px -20px rgba(10, 37, 64, .28);
            --shadow-sm: 0 6px 18px -8px rgba(10, 37, 64, .18);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; scroll-padding-top: 76px; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }

        body {
            margin: 0;
            background: var(--surface);
            color: var(--ink);
            font-family: Tajawal, "Segoe UI", Tahoma, system-ui, sans-serif;
            font-weight: 500;
            font-size: 16px;
            line-height: 1.75;
            -webkit-text-size-adjust: 100%;
            overflow-x: hidden;
        }

        h1, h2, h3, p, ul { margin: 0; }
        h1, h2, h3 { font-weight: 800; line-height: 1.35; text-wrap: balance; }
        ul { padding: 0; list-style: none; }
        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; display: block; }
        :focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; border-radius: 8px; }

        .shell { width: min(1140px, 100% - 32px); margin-inline: auto; }
        .section { padding: 56px 0; }
        @media (min-width: 760px) { .section { padding: 88px 0; } }
        .section.tint { background: var(--surface-2); }

        .eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--brand); font-size: 14px; font-weight: 800;
            background: var(--brand-50); border: 1px solid var(--brand-100);
            padding: 4px 14px; border-radius: 999px;
        }

        .head { max-width: 680px; margin: 0 auto 48px; text-align: center; }
        .head h2 { font-size: clamp(26px, 4vw, 38px); margin-top: 14px; color: var(--brand-ink); }
        .head p { margin-top: 12px; color: var(--muted); font-size: 17px; }

        /* ---------- Buttons ---------- */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            min-height: 52px; padding: 12px 24px; border-radius: 14px;
            border: 1px solid transparent; font: inherit; font-weight: 800; font-size: 16px;
            cursor: pointer; transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: var(--brand); color: #fff; box-shadow: 0 10px 24px -10px rgba(1, 116, 214, .7); }
        .btn-primary:hover { background: var(--brand-deep); }
        .btn-wa { background: var(--whatsapp); color: #fff; }
        .btn-light { background: var(--surface); border-color: var(--line); color: var(--ink); }
        .btn-light:hover { border-color: var(--brand-100); background: var(--brand-50); }
        .btn-sm { min-height: 44px; padding: 8px 16px; font-size: 15px; border-radius: 12px; }

        /* ---------- Header ---------- */
        .top {
            position: sticky; top: 0; z-index: 50;
            background: rgba(255, 255, 255, .86);
            backdrop-filter: saturate(1.6) blur(14px);
            -webkit-backdrop-filter: saturate(1.6) blur(14px);
            border-bottom: 1px solid transparent;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .top.scrolled { border-bottom-color: var(--line); box-shadow: 0 6px 24px -18px rgba(10, 37, 64, .4); }
        .top .shell { display: flex; align-items: center; gap: 24px; min-height: 68px; }
        .logo { display: inline-flex; align-items: center; gap: 10px; min-height: 44px; font-weight: 800; font-size: 20px; color: var(--brand-ink); }
        .logo img { width: 38px; height: 38px; border-radius: 10px; }
        .nav { display: none; gap: 4px; margin-inline-start: 12px; }
        .nav a { padding: 8px 12px; border-radius: 10px; color: var(--ink-soft); font-size: 15px; }
        .nav a:hover, .nav a.active { color: var(--brand); background: var(--brand-50); }
        .nav a.active { font-weight: 800; }
        /* How far down the page you are — a hairline under the header. */
        .progress { position: absolute; inset-inline: 0; bottom: -1px; height: 2px; background: linear-gradient(90deg, var(--brand), #22A3E8); transform-origin: right; transform: scaleX(0); }
        .top-actions { display: flex; align-items: center; gap: 8px; margin-inline-start: auto; }
        .top-actions .btn { white-space: nowrap; }
        @media (max-width: 380px) {
            .top .shell { gap: 12px; }
            .logo { font-size: 18px; gap: 8px; }
            .logo img { width: 34px; height: 34px; }
            .top-actions .btn-sm { padding: 8px 12px; font-size: 14px; }
        }
        .top-actions .login { display: none; }

        .menu { position: relative; }
        .menu summary {
            list-style: none; display: grid; place-items: center;
            width: 44px; height: 44px; border-radius: 12px; border: 1px solid var(--line);
            cursor: pointer; color: var(--ink);
        }
        .menu summary::-webkit-details-marker { display: none; }
        .menu-panel {
            position: absolute; inset-inline-end: 0; top: calc(100% + 10px);
            width: min(280px, calc(100vw - 32px));
            background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
            box-shadow: var(--shadow); padding: 8px;
        }
        .menu-panel a { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 10px; font-weight: 800; }
        .menu-panel a:hover { background: var(--brand-50); color: var(--brand); }
        .menu-panel hr { border: 0; border-top: 1px solid var(--line); margin: 6px 0; }

        @media (min-width: 1000px) {
            .nav { display: flex; }
            .top-actions .login { display: inline-flex; }
            .menu { display: none; }
        }

        /* ---------- Hero ---------- */
        .hero {
            position: relative; overflow: hidden;
            padding: 56px 0 88px;
            background:
                radial-gradient(900px 480px at 85% -10%, var(--brand-100), transparent 60%),
                radial-gradient(700px 420px at 0% 30%, #E8F7EE, transparent 60%),
                var(--surface);
        }
        .hero::before {
            content: ""; position: absolute; inset: 0; pointer-events: none;
            background-image: linear-gradient(var(--line) 1px, transparent 1px), linear-gradient(90deg, var(--line) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: radial-gradient(ellipse at 50% 0%, #000 0%, transparent 70%);
            -webkit-mask-image: radial-gradient(ellipse at 50% 0%, #000 0%, transparent 70%);
            opacity: .55;
        }
        .hero .shell { position: relative; display: grid; gap: 48px; align-items: center; }
        .hero h1 { font-size: clamp(28px, 4.4vw, 44px); color: var(--brand-ink); margin-top: 18px; letter-spacing: -.01em; }
        .hero h1 em {
            font-style: normal; color: var(--brand);
            background: linear-gradient(90deg, var(--brand), #22A3E8); -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero .lead { margin-top: 18px; font-size: clamp(17px, 2vw, 19px); color: var(--ink-soft); max-width: 600px; }
        .hero-ctas { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 30px; }
        .hero-points { display: flex; flex-wrap: wrap; gap: 10px 22px; margin-top: 26px; color: var(--ink-soft); font-size: 15px; }
        .hero-points li { display: inline-flex; align-items: center; gap: 8px; }
        .hero-points svg { color: var(--whatsapp); }

        .hero-visual { position: relative; isolation: isolate; display: flex; justify-content: center; padding: 10px 0 20px; }
        /* A soft light behind the phone, so it stands off the page. */
        .hero-visual::before {
            content: ""; position: absolute; top: 50%; left: 50%; width: min(440px, 92vw); aspect-ratio: 1;
            transform: translate(-50%, -50%); border-radius: 50%; z-index: -1;
            background: radial-gradient(circle, rgba(1, 116, 214, .22), rgba(34, 163, 232, .1) 45%, transparent 70%);
            filter: blur(8px);
        }
        .hero-visual .phone { z-index: 0; }
        .float-card {
            position: absolute; display: flex; align-items: center; gap: 12px;
            background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
            padding: 12px 16px; box-shadow: var(--shadow); font-size: 14px; line-height: 1.45;
            animation: bob 6s ease-in-out infinite;
        }
        .float-card b { display: block; color: var(--brand-ink); font-size: 15px; }
        .float-card span { color: var(--muted); }
        .float-card .ic { flex: none; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; }
        .float-card.queue { top: 16%; inset-inline-start: 0; }
        .float-card.queue .ic { background: var(--brand-50); color: var(--brand); }
        .float-card.confirm { bottom: 14%; inset-inline-end: 0; animation-delay: -3s; }
        .float-card.confirm .ic { background: #E7F6EC; color: var(--whatsapp); }
        @keyframes bob { 50% { transform: translateY(-8px); } }
        @media (prefers-reduced-motion: reduce) { .float-card { animation: none; } }

        @media (min-width: 960px) {
            .hero { padding: 72px 0 104px; }
            .hero .shell { grid-template-columns: 1.1fr .9fr; }
        }
        @media (max-width: 520px) {
            .hero-ctas .btn { flex: 1 1 100%; }
            .float-card { padding: 10px 12px; font-size: 13px; }
            .float-card.queue { inset-inline-start: -4px; }
            .float-card.confirm { inset-inline-end: -4px; }
        }

        /* ---------- Phone frame ---------- */
        .phone {
            position: relative; width: 270px; flex: none;
            border-radius: 40px; padding: 10px;
            background: linear-gradient(160deg, #22344A, #0A1726);
            box-shadow: var(--shadow), inset 0 0 0 1px rgba(255, 255, 255, .08);
        }
        .phone img { width: 100%; height: auto; aspect-ratio: 1 / 2; object-fit: cover; object-position: top; border-radius: 31px; background: var(--surface-2); }
        .hero .phone { width: min(300px, 74vw); }

        /* ---------- Icon tile, shared by every section ---------- */
        .tile {
            display: grid; place-items: center; width: 50px; height: 50px; border-radius: 14px;
            background: var(--brand-50); color: var(--brand);
        }

        /* ---------- Problem → solution pairs ---------- */
        .pairs { display: grid; gap: 20px; }
        @media (min-width: 860px) { .pairs { grid-template-columns: 1fr 1fr; } }
        .pair {
            display: flex; flex-direction: column; overflow: hidden;
            background: var(--surface); border: 1px solid var(--line); border-radius: 22px;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .pair:hover { transform: translateY(-3px); box-shadow: var(--shadow-sm); border-color: var(--brand-100); }
        .pair-problem {
            display: flex; align-items: center; gap: 10px; padding: 14px 22px;
            background: #FFF6F1; border-bottom: 1px dashed #F6D5C3; color: #9A3412; font-size: 15.5px;
        }
        .pair-problem .ic { display: grid; color: var(--danger); }
        /* Crossed out: the problem the solution below takes away. */
        .pair-problem s { text-decoration-color: rgba(194, 65, 12, .45); text-decoration-thickness: 2px; }
        .pair-problem small {
            margin-inline-start: auto; flex: none; font-weight: 800; font-size: 12.5px;
            background: #FFE6D8; padding: 2px 10px; border-radius: 999px;
        }
        .pair-solution { display: flex; gap: 16px; align-items: flex-start; padding: 24px 22px; flex: 1; }
        .pair-solution .tile { flex: none; }
        .pair-solution .label { display: inline-flex; align-items: center; gap: 4px; color: var(--whatsapp); font-weight: 800; font-size: 13.5px; }
        .pair-solution h3 { font-size: 20px; color: var(--brand-ink); margin-top: 2px; }
        .pair-solution p { color: var(--muted); margin-top: 6px; font-size: 15.5px; }
        .tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .tags li {
            font-size: 13px; font-weight: 800; color: var(--brand);
            background: var(--brand-50); border: 1px solid var(--brand-100); padding: 3px 12px; border-radius: 999px;
        }
        @media (max-width: 520px) {
            .pair-solution { flex-direction: column; gap: 12px; padding: 20px 18px; }
            .pair-problem { flex-wrap: wrap; row-gap: 6px; padding: 12px 18px; }
            .pair-problem small { order: -1; flex-basis: 100%; width: fit-content; max-width: fit-content; margin-inline-start: 0; }
        }

        /* ---------- Roles (tabs) ---------- */
        .tabs { display: flex; justify-content: center; gap: 6px; padding: 6px; margin: 0 auto 28px; width: fit-content; max-width: 100%; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; }
        .tabs button {
            display: inline-flex; align-items: center; gap: 8px;
            font: inherit; font-weight: 800; border: 0; background: transparent; color: var(--muted);
            padding: 10px 18px; border-radius: 12px; cursor: pointer; white-space: nowrap;
        }
        .tabs button[aria-selected="true"] { background: var(--brand); color: #fff; box-shadow: 0 8px 18px -10px rgba(1, 116, 214, .8); }
        html:not(.js) .tabs { display: none; }
        /* Three equal tabs on a phone, so none is pushed off the edge. */
        @media (max-width: 520px) {
            .tabs { display: grid; grid-template-columns: repeat(3, 1fr); width: 100%; }
            .tabs button { justify-content: center; gap: 6px; padding: 10px 6px; font-size: 15px; }
        }
        @media (max-width: 360px) { .tabs button svg { display: none; } }
        .panels { display: grid; gap: 18px; }
        .js .panel[hidden] { display: none; }
        html:not(.js) .panel[hidden] { display: grid; }
        .panel {
            display: grid; gap: 26px; align-items: center;
            background: var(--surface); border: 1px solid var(--line); border-radius: 26px; padding: 22px;
        }
        @media (min-width: 900px) { .panel { grid-template-columns: 1fr 1fr; gap: 40px; padding: 26px; padding-inline-start: 44px; } }
        .panel-text { padding: 8px 6px; }
        .panel h3 { font-size: clamp(22px, 3vw, 28px); color: var(--brand-ink); margin: 14px 0 18px; }
        .panel .tile { width: 56px; height: 56px; }
        .ticks { display: grid; gap: 10px; }
        .ticks li { display: flex; gap: 10px; align-items: flex-start; padding: 12px 16px; background: var(--surface-2); border-radius: 14px; }
        .ticks svg { flex: none; margin-top: 4px; color: var(--whatsapp); }

        /* ---------- Role previews: a small picture of what each person sees ---------- */
        .role-visual {
            position: relative; display: grid; place-items: center; gap: 14px;
            min-height: 340px; padding: 34px 22px; border-radius: 20px; overflow: hidden;
            background:
                radial-gradient(circle at 20% 15%, rgba(255, 255, 255, .9), transparent 40%),
                radial-gradient(circle at 85% 90%, #D7F0E0, transparent 45%),
                linear-gradient(160deg, var(--brand-50), var(--brand-100));
        }
        /* A faint dot grid behind the card. */
        .role-visual::before {
            content: ""; position: absolute; inset: 0; opacity: .5;
            background-image: radial-gradient(rgba(1, 116, 214, .25) 1px, transparent 1.5px);
            background-size: 18px 18px;
        }
        @media (max-width: 899px) { .role-visual { order: -1; min-height: 0; } }
        @media (max-width: 520px) {
            .panel { padding: 12px; gap: 18px; }
            .panel-text { padding: 4px 8px 14px; }
            .role-visual { padding: 20px 10px; border-radius: 16px; }
            .mock { padding: 14px; }
        }

        .mock {
            position: relative; width: min(330px, 100%); padding: 18px;
            background: var(--surface); border-radius: 20px; box-shadow: var(--shadow);
            font-size: 14px; line-height: 1.5; color: var(--ink);
        }
        .mock b { font-weight: 800; }

        .mock-head { display: flex; align-items: center; gap: 12px; }
        .mock-head img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; }
        .mock-head span { display: block; color: var(--muted); font-size: 13px; }
        .mock-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 16px; }
        .mock-stats div { padding: 10px 12px; border-radius: 14px; background: var(--surface-2); }
        .mock-stats span { display: block; color: var(--muted); font-size: 12.5px; }
        .mock-stats div { min-width: 0; }
        .mock-stats b { display: block; font-size: clamp(15px, 4.4vw, 20px); color: var(--brand-ink); white-space: nowrap; }
        .mock-bars { display: flex; align-items: flex-end; gap: 7px; height: 70px; margin-top: 16px; padding: 0 2px; }
        .mock-bars i { flex: 1; border-radius: 6px 6px 3px 3px; background: var(--brand-100); }
        .mock-bars i:last-child { background: var(--brand); }
        .mock-foot { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 4px 8px; margin-top: 10px; font-size: 12.5px; color: var(--muted); }
        .mock-foot > * { white-space: nowrap; }
        .mock-foot em { display: inline-flex; align-items: center; gap: 4px; font-style: normal; font-weight: 800; color: #15803D; }

        .mock-title { display: flex; justify-content: space-between; align-items: center; }
        .chip { font-size: 12px; font-weight: 800; color: var(--brand); background: var(--brand-50); padding: 2px 10px; border-radius: 999px; }
        .mock-queue { display: grid; gap: 8px; margin-top: 14px; }
        .mock-queue li { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 12px; background: var(--surface-2); }
        .mock-queue time { font-size: 12.5px; color: var(--muted); direction: ltr; unicode-bidi: isolate; }
        .st { font-size: 11.5px; font-weight: 800; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
        .st-with_doctor { color: #fff; background: var(--brand); }
        .st-arrived { color: #15803D; background: #DCF3E4; }
        .st-booked { color: var(--ink-soft); background: #E6ECF2; }
        .mock-cta {
            display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 14px; padding: 10px;
            border-radius: 12px; background: var(--brand); color: #fff; font-weight: 800;
        }

        .mock-track { display: grid; justify-items: center; gap: 12px; text-align: center; padding: 22px 18px; }
        .mock-caption { color: var(--muted); font-weight: 800; font-size: 13px; }
        .ring {
            display: grid; place-items: center; width: 132px; height: 132px; border-radius: 50%;
            background: conic-gradient(var(--brand) 0 28%, var(--brand-50) 28% 100%);
        }
        .ring > div { display: grid; place-items: center; width: 108px; height: 108px; border-radius: 50%; background: var(--surface); line-height: 1.1; }
        .ring b { font-size: 40px; color: var(--brand); }
        .ring span { color: var(--muted); font-size: 13px; }
        .mock-eta { display: inline-flex; align-items: center; gap: 6px; font-weight: 800; color: var(--brand-ink); font-size: 13.5px; }
        .wa-bubble {
            position: relative; display: flex; align-items: center; gap: 10px; width: min(300px, 100%);
            padding: 12px 14px; border-radius: 16px 16px 16px 4px; background: #E7F8EC; border: 1px solid #C8EBD3;
            box-shadow: var(--shadow-sm); font-size: 13.5px; line-height: 1.45;
        }
        .wa-bubble .ic { flex: none; display: grid; place-items: center; width: 36px; height: 36px; border-radius: 50%; background: var(--whatsapp); color: #fff; }
        .wa-bubble b { display: block; color: #14532D; }
        .wa-bubble span { color: #3F6B4F; }

        /* ---------- Screens ---------- */
        .screens {
            display: flex; gap: 26px; overflow-x: auto; scroll-snap-type: x mandatory;
            padding: 8px 4px 28px; scrollbar-width: thin;
        }
        .screens figure { margin: 0; scroll-snap-align: center; display: grid; justify-items: center; gap: 16px; }
        .screens figcaption { display: grid; gap: 2px; text-align: center; max-width: 240px; }
        .screens figcaption b { font-weight: 800; color: var(--brand-ink); font-size: 17px; }
        .screens figcaption span { color: var(--muted); font-size: 14px; }
        .screens figure .phone { transition: transform .25s ease, box-shadow .25s ease; }
        @media (hover: hover) { .screens figure:hover .phone { transform: translateY(-6px); } }
        .screen-dots { display: flex; justify-content: center; gap: 8px; margin-top: 4px; }
        .screen-dots i { width: 8px; height: 8px; border-radius: 999px; background: #C9D6E4; transition: width .2s ease, background .2s ease; }
        .screen-dots i.on { width: 22px; background: var(--brand); }
        @media (min-width: 1100px) { .screen-dots { display: none; } }
        .screens .phone { width: 240px; }
        @media (min-width: 1100px) { .screens { justify-content: center; overflow: visible; } }

        /* ---------- Dark band (the roles section) ---------- */
        .dark { background: linear-gradient(135deg, var(--brand-ink), var(--brand-deep)); color: #fff; }
        .dark .eyebrow { background: rgba(255, 255, 255, .1); border-color: rgba(255, 255, 255, .18); color: #fff; }
        .dark .head h2 { color: #fff; }
        .dark .tabs { background: rgba(255, 255, 255, .08); border-color: rgba(255, 255, 255, .16); }
        .dark .tabs button { color: #C9D8EA; }
        .dark .tabs button:hover { color: #fff; }
        .dark .tabs button[aria-selected="true"] { background: #fff; color: var(--brand-deep); box-shadow: 0 10px 24px -12px rgba(0, 0, 0, .5); }
        .dark .panel { color: var(--ink); border-color: transparent; box-shadow: 0 30px 60px -30px rgba(0, 0, 0, .55); }

        /* ---------- FAQ ---------- */
        .faq-wrap { display: grid; gap: 24px; align-items: start; }
        @media (min-width: 960px) { .faq-wrap { grid-template-columns: 1fr 340px; gap: 32px; } .faq-help { position: sticky; top: 96px; } }
        .faq { display: grid; gap: 12px; }
        .faq-help {
            padding: 28px; border-radius: 22px; color: #fff;
            background: radial-gradient(320px 200px at 100% 0%, rgba(34, 163, 232, .35), transparent 60%), linear-gradient(150deg, var(--brand-ink), var(--brand-deep));
            box-shadow: var(--shadow);
        }
        .faq-help .tile { background: rgba(255, 255, 255, .12); color: #fff; }
        .faq-help h3 { margin-top: 16px; font-size: 21px; }
        .faq-help p { margin-top: 6px; color: #C9D8EA; }
        .faq-help-actions { display: grid; gap: 10px; margin-top: 20px; }
        .faq-help .btn-light { background: rgba(255, 255, 255, .1); border-color: rgba(255, 255, 255, .22); color: #fff; }
        .faq-help .btn-light:hover { background: rgba(255, 255, 255, .18); }
        .faq details { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; transition: border-color .2s ease; }
        .faq details[open] { border-color: var(--brand-100); box-shadow: var(--shadow-sm); }
        .faq summary {
            list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 20px 22px; font-weight: 800; font-size: 17px; color: var(--brand-ink);
        }
        .faq summary::-webkit-details-marker { display: none; }
        .faq summary::after {
            content: "+"; flex: none; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%;
            background: var(--brand-50); color: var(--brand); font-size: 20px; line-height: 1; transition: transform .2s ease;
        }
        .faq details[open] summary::after { transform: rotate(45deg); }
        .faq details p { padding: 0 22px 20px; color: var(--muted); }

        /* ---------- Contact ---------- */
        .contact-card {
            position: relative; overflow: hidden; color: #fff;
            padding: 52px 40px; border-radius: 28px; text-align: center;
            background:
                radial-gradient(520px 260px at 0% 110%, rgba(255, 255, 255, .16), transparent 60%),
                radial-gradient(420px 220px at 100% -10%, rgba(31, 175, 84, .25), transparent 60%),
                linear-gradient(135deg, var(--brand), var(--brand-deep));
            box-shadow: var(--shadow);
        }
        .contact-head { max-width: 620px; margin: 0 auto; }
        .contact-head .eyebrow { background: rgba(255, 255, 255, .14); border-color: rgba(255, 255, 255, .24); color: #fff; }
        .contact-head h2 { margin-top: 14px; font-size: clamp(28px, 4vw, 40px); }
        .contact-head p { margin-top: 10px; color: #DCEBFB; font-size: 17px; }
        .channels { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; margin-top: 36px; }
        .channel {
            flex: 1 1 240px; max-width: 320px; display: grid; justify-items: center; gap: 4px;
            padding: 26px 20px 22px; border-radius: 20px; background: #fff; color: var(--ink);
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .channel:hover { transform: translateY(-3px); box-shadow: 0 18px 36px -18px rgba(0, 0, 0, .45); }
        .channel-ic {
            display: grid; place-items: center; width: 54px; height: 54px; margin-bottom: 8px;
            border-radius: 16px; background: var(--brand-50); color: var(--brand);
        }
        .channel b { font-size: 18px; color: var(--brand-ink); }
        .channel small { color: var(--muted); font-size: 13.5px; }
        .channel-wa .channel-ic { background: var(--whatsapp); color: #fff; }

        /* The action opens the app; the square beside it copies the value. */
        .channel-actions { display: flex; gap: 8px; width: 100%; margin-top: 16px; }
        .channel-go { flex: 1; min-height: 46px; font-size: 15px; background: var(--brand); color: #fff; }
        .channel-go:hover { background: var(--brand-deep); }
        .channel-wa .channel-go { background: var(--whatsapp); }
        .channel-wa .channel-go:hover { background: #17924A; }
        .copy {
            flex: none; display: grid; place-items: center; width: 46px; height: 46px; padding: 0;
            border-radius: 12px; border: 1px solid var(--line); background: var(--surface-2); color: var(--ink-soft);
            cursor: pointer; transition: background .15s ease, color .15s ease, border-color .15s ease;
        }
        .copy:hover { background: var(--brand-50); border-color: var(--brand-100); color: var(--brand); }
        .copy .copy-done { display: none; }
        .copy.done { background: #E7F6EC; border-color: #BFE6CC; color: #15803D; }
        .copy.done .copy-idle { display: none; }
        .copy.done .copy-done { display: grid; }
        /* Copying needs a script; without one the button would do nothing. */
        html:not(.js) .copy { display: none; }
        .copied-note { min-height: 1.6em; margin-top: 14px; color: #DCEBFB; font-size: 14px; font-weight: 800; }
        @media (max-width: 620px) {
            .contact-card { padding: 34px 14px 20px; border-radius: 22px; }
            .channels { gap: 10px; margin-top: 26px; }
            /* One row each: icon, name and hint, then the two buttons. */
            .channel {
                flex-basis: 100%; max-width: none;
                grid-template-columns: auto 1fr; grid-template-areas: "ic name" "ic hint" "act act";
                justify-items: start; text-align: start; column-gap: 12px; row-gap: 0; padding: 14px;
            }
            .channel-ic { grid-area: ic; margin: 0; width: 46px; height: 46px; border-radius: 14px; align-self: center; }
            .channel b { grid-area: name; font-size: 16.5px; }
            .channel small { grid-area: hint; }
            .channel-actions { grid-area: act; margin-top: 12px; }
            .channel-go { min-height: 44px; }
            .copy { width: 44px; height: 44px; }
        }

        /* ---------- Footer ---------- */
        footer { background: var(--brand-ink); color: #C9D8EA; padding: 48px 0 24px; }
        .foot { display: grid; gap: 32px; }
        .foot { grid-template-columns: 1fr 1fr; }
        .foot > :first-child { grid-column: 1 / -1; }
        @media (min-width: 860px) { .foot { grid-template-columns: 1.4fr 1fr 1fr; } .foot > :first-child { grid-column: auto; } }
        footer .logo { color: #fff; }
        footer p { margin-top: 12px; max-width: 360px; }
        footer h4 { margin: 0 0 12px; color: #fff; font-size: 15px; }
        footer li a { display: inline-flex; align-items: center; min-height: 44px; }
        @media (min-width: 860px) { footer li a { min-height: 36px; } }
        footer a:hover { color: #fff; }
        .rights { max-width: none; margin-top: 40px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, .12); font-size: 14px; }

        /* ---------- Floating WhatsApp ---------- */
        .wa-float {
            position: fixed; z-index: 40; bottom: 18px; inset-inline-end: 18px;
            display: grid; place-items: center; width: 56px; height: 56px; border-radius: 50%;
            background: var(--whatsapp); color: #fff; box-shadow: 0 14px 30px -10px rgba(31, 175, 84, .8);
            transition: transform .2s ease, opacity .2s ease;
        }
        .js .wa-float:not(.shown) { opacity: 0; transform: translateY(12px); pointer-events: none; }
        .wa-float:hover { transform: scale(1.06); }
        .wa-float svg { width: 28px; height: 28px; }

        /* ---------- Opening screen ----------
           Shown while the page loads, at least long enough for one pass of the
           animation. The CSS failsafe removes it at 2.6s even if no script
           ever runs, so it can never trap the page. */
        .splash {
            position: fixed; inset: 0; z-index: 10000;
            display: grid; place-content: center; justify-items: center; gap: 22px;
            background:
                radial-gradient(600px 400px at 50% 38%, rgba(255, 255, 255, .14), transparent 70%),
                linear-gradient(160deg, var(--brand), var(--brand-ink));
            color: #fff;
            animation: splash-failsafe .4s ease 2.6s forwards;
        }
        .no-splash .splash { display: none; }
        .splash.done { animation: splash-out .5s ease forwards; }
        @keyframes splash-failsafe { to { opacity: 0; visibility: hidden; } }
        @keyframes splash-out { to { opacity: 0; visibility: hidden; } }

        /* The mark breathes; a band of light passes across its rings only. */
        .splash-mark {
            position: relative; width: min(220px, 56vw); aspect-ratio: 1024 / 480;
            animation: splash-in .7s cubic-bezier(.2, .8, .2, 1) both, splash-breathe 2.4s ease-in-out .7s infinite;
        }
        /* A touch below full white, so the light passing over it shows. */
        .splash-mark img { width: 100%; height: 100%; display: block; opacity: .86; }
        .splash-shine {
            position: absolute; inset: 0;
            background: linear-gradient(105deg, transparent 30%, rgba(255, 255, 255, .95) 50%, transparent 70%) no-repeat;
            background-size: 250% 100%;
            -webkit-mask: url("{{ asset(config('clinic.brand.logo_white')) }}") center / contain no-repeat;
            mask: url("{{ asset(config('clinic.brand.logo_white')) }}") center / contain no-repeat;
            animation: splash-shine 1.8s ease-in-out .4s infinite;
        }
        .splash.done .splash-mark { animation: splash-lift .5s ease forwards; }
        @keyframes splash-in { from { opacity: 0; transform: scale(.82); } }
        @keyframes splash-breathe { 50% { transform: scale(1.045); } }
        @keyframes splash-shine { from { background-position: 150% 0; } to { background-position: -50% 0; } }
        @keyframes splash-lift { to { transform: translateY(-14px) scale(1.08); opacity: 0; } }

        /* An endless ∞, drawn over and over — the rings' own idea, as a loader. */
        .splash-loop { width: 74px; height: 26px; overflow: visible; }
        .splash-loop path { fill: none; stroke-linecap: round; stroke-width: 3.2; }
        .splash-loop .track { stroke: rgba(255, 255, 255, .18); }
        .splash-loop .run {
            stroke: #fff; stroke-dasharray: 24 76; stroke-dashoffset: 0;
            animation: splash-run 1.4s linear infinite;
        }
        @keyframes splash-run { to { stroke-dashoffset: -100; } }
        .splash-name { font-weight: 800; font-size: 20px; letter-spacing: .02em; opacity: 0; animation: splash-fade .6s ease .35s forwards; }
        @keyframes splash-fade { to { opacity: .92; } }

        @media (prefers-reduced-motion: reduce) {
            .splash-mark, .splash-shine, .splash-loop .run, .splash-name { animation: none; opacity: 1; }
            .splash { animation-delay: 1.2s; }
            .splash.done, .splash.done .splash-mark { animation-duration: .01s; }
        }

        /* ---------- Reveal on scroll (only when scripting is on) ---------- */
        .js .reveal { opacity: 0; transform: translateY(18px); transition: opacity .6s ease, transform .6s ease; }
        .js .reveal.in { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) { .js .reveal { opacity: 1; transform: none; transition: none; } }
    </style>
</head>
<body>
<div class="splash" id="splash" aria-hidden="true">
    <div class="splash-mark">
        <img src="{{ asset(config('clinic.brand.logo_white')) }}" alt="" width="1024" height="480">
        <span class="splash-shine"></span>
    </div>
    <svg class="splash-loop" viewBox="0 0 74 26">
        <path class="track" pathLength="100" d="M37 13C31 5 17 3 12 8c-5 5-2 12 4 13 7 1 15-1 21-8s14-9 21-8c6 1 9 8 4 13-5 5-19 3-25-5Z"/>
        <path class="run" pathLength="100" d="M37 13C31 5 17 3 12 8c-5 5-2 12 4 13 7 1 15-1 21-8s14-9 21-8c6 1 9 8 4 13-5 5-19 3-25-5Z"/>
    </svg>
    <span class="splash-name">{{ $brand }}</span>
</div>
@include('partials.test-site-banner')

<header class="top" id="top">
    <div class="shell">
        <a class="logo" href="{{ route('root') }}" aria-label="{{ $brand }}">
            <img src="{{ asset(config('clinic.brand.logo')) }}" alt="" width="38" height="38">
            <span>{{ $brand }}</span>
        </a>

        <nav class="nav" aria-label="{{ __('site.nav.menu') }}">
            @foreach ($nav as $item)
                <a href="#{{ $item }}">{{ __('site.nav.'.$item) }}</a>
            @endforeach
        </nav>

        <div class="top-actions">
            @if ($showLogin)
                <a class="btn btn-light btn-sm login" href="{{ route('app.login') }}">{!! $icon('login', 18) !!} {{ __('site.nav.login') }}</a>
            @endif
            <a class="btn btn-primary btn-sm" href="#contact">{{ __('site.nav.demo') }}</a>

            <details class="menu">
                <summary aria-label="{{ __('site.nav.menu') }}">{!! $icon('menu') !!}</summary>
                <div class="menu-panel">
                    @foreach ([...$nav, 'contact'] as $item)
                        <a href="#{{ $item }}">{{ __('site.nav.'.$item) }}</a>
                    @endforeach
                    @if ($showLogin)
                        <hr>
                        <a href="{{ route('app.login') }}">{!! $icon('login', 18) !!} {{ __('site.nav.login') }}</a>
                    @endif
                </div>
            </details>
        </div>
    </div>
    <span class="progress" aria-hidden="true"></span>
</header>

<main>
    {{-- ================= Hero ================= --}}
    <section class="hero">
        <div class="shell">
            <div>
                <span class="eyebrow">{{ __('site.hero.eyebrow') }}</span>
                <h1>{!! str_replace(e(__('site.hero.title_highlight')), '<em>'.e(__('site.hero.title_highlight')).'</em>', e(__('site.hero.title'))) !!}</h1>
                <p class="lead">{{ __('site.hero.lead') }}</p>

                <div class="hero-ctas">
                    <a class="btn btn-primary" href="#contact">{{ __('site.hero.cta') }} {!! $icon('arrow', 18) !!}</a>
                    @if ($whatsappLink)
                        <a class="btn btn-wa" href="{{ $whatsappLink }}" target="_blank" rel="noopener">{!! $waIcon !!} {{ __('site.hero.whatsapp') }}</a>
                    @else
                        <a class="btn btn-light" href="#screens">{{ __('site.hero.see_how') }}</a>
                    @endif
                </div>

                <ul class="hero-points">
                    @foreach (__('site.hero.points') as $point)
                        <li>{!! $icon('check', 18) !!} {{ $point }}</li>
                    @endforeach
                </ul>
            </div>

            <div class="hero-visual" aria-hidden="true">
                <div class="phone">
                    <img src="{{ asset($screens['doctor_page']) }}" alt="" width="390" height="780" fetchpriority="high">
                </div>
                <div class="float-card queue">
                    <span class="ic">{!! $icon('queue') !!}</span>
                    <div><b>{{ __('site.hero.card_queue_title') }}</b><span>{{ __('site.hero.card_queue_body') }}</span></div>
                </div>
                <div class="float-card confirm">
                    <span class="ic">{!! $waIcon !!}</span>
                    <div><b>{{ __('site.hero.card_confirm_title') }}</b><span>{{ __('site.hero.card_confirm_body') }}</span></div>
                </div>
            </div>
        </div>
    </section>

    {{-- ================= Problems, each beside its solution ================= --}}
    <section class="section tint" id="features" aria-labelledby="features-title">
        <div class="shell">
            <div class="head reveal">
                <span class="eyebrow">{{ __('site.features.eyebrow') }}</span>
                <h2 id="features-title">{{ __('site.features.title') }}</h2>
                <p>{{ __('site.features.lead') }}</p>
            </div>
            <div class="pairs">
                @foreach (__('site.features.items') as $i => $item)
                    <article class="pair reveal">
                        <div class="pair-problem">
                            <span class="ic">{!! $icon($pairIcons[$i][0], 18) !!}</span>
                            <s>{{ $item['problem'] }}</s>
                            <small>{{ __('site.features.problem_label') }}</small>
                        </div>
                        <div class="pair-solution">
                            <span class="tile">{!! $icon($pairIcons[$i][1]) !!}</span>
                            <div>
                                <span class="label">{!! $icon('check', 14) !!} {{ __('site.features.solution_label') }}</span>
                                <h3>{{ $item['title'] }}</h3>
                                <p>{{ $item['body'] }}</p>
                                <ul class="tags">
                                    @foreach ($item['tags'] as $tag)
                                        <li>{{ $tag }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================= For each role ================= --}}
    <section class="section dark" id="roles" aria-labelledby="roles-title">
        <div class="shell">
            <div class="head reveal">
                <span class="eyebrow">{{ __('site.roles.eyebrow') }}</span>
                <h2 id="roles-title">{{ __('site.roles.title') }}</h2>
            </div>

            <div class="tabs" role="tablist" aria-label="{{ __('site.roles.title') }}">
                @foreach (__('site.roles.tabs') as $key => $tab)
                    <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}">
                        {!! $icon($roleIcons[$key], 18) !!} {{ $tab['label'] }}
                    </button>
                @endforeach
            </div>

            <div class="panels">
                @foreach (__('site.roles.tabs') as $key => $tab)
                    <div class="panel reveal" role="tabpanel" id="panel-{{ $key }}" aria-labelledby="tab-{{ $key }}" @unless ($loop->first) hidden @endunless>
                        <div class="panel-text">
                            <span class="tile">{!! $icon($roleIcons[$key], 26) !!}</span>
                            <h3>{{ $tab['title'] }}</h3>
                            <ul class="ticks">
                                @foreach ($tab['items'] as $item)
                                    <li>{!! $icon('check', 18) !!} <span>{{ $item }}</span></li>
                                @endforeach
                            </ul>
                        </div>

                        {{-- What this person sees, drawn small. Decorative: the list beside it says it in words. --}}
                        <div class="role-visual role-visual-{{ $key }}" aria-hidden="true">
                            @php($preview = __('site.roles.preview.'.$key))
                            @if ($key === 'doctor')
                                <div class="mock">
                                    <div class="mock-head">
                                        <img src="{{ asset('images/avatars/female.png') }}" alt="" width="44" height="44" loading="lazy">
                                        <div><b>{{ $preview['greeting'] }}</b><span>{{ $preview['subtitle'] }}</span></div>
                                    </div>
                                    <div class="mock-stats">
                                        <div><span>{{ $preview['visits_label'] }}</span><b>{{ $preview['visits'] }}</b></div>
                                        <div><span>{{ $preview['income_label'] }}</span><b>{{ $preview['income'] }}</b></div>
                                    </div>
                                    <div class="mock-bars">
                                        @foreach ([46, 62, 38, 70, 55, 84, 92] as $height)
                                            <i style="height: {{ $height }}%"></i>
                                        @endforeach
                                    </div>
                                    <div class="mock-foot">
                                        <span>{{ $preview['week'] }}</span>
                                        <em>{!! $icon('chart', 14) !!} {{ $preview['change'] }}</em>
                                    </div>
                                </div>
                            @elseif ($key === 'secretary')
                                <div class="mock">
                                    <div class="mock-title"><b>{{ $preview['title'] }}</b><span class="chip">{{ $preview['count'] }}</span></div>
                                    <ul class="mock-queue">
                                        @foreach ($preview['rows'] as $row)
                                            @php($status = \App\Enums\BookingStatus::from($row['status']))
                                            <li>
                                                <time>{{ $row['time'] }}</time>
                                                <b>{{ $row['name'] }}</b>
                                                <span class="st st-{{ $status->value }}">{{ $status->label() }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <span class="mock-cta">{!! $icon('calendar', 16) !!} {{ $preview['new_booking'] }}</span>
                                </div>
                            @else
                                <div class="mock mock-track">
                                    <span class="mock-caption">{{ $preview['title'] }}</span>
                                    <div class="ring"><div><b>{{ $preview['ahead'] }}</b><span>{{ $preview['ahead_label'] }}</span></div></div>
                                    <span class="mock-eta">{!! $icon('clock', 15) !!} {{ $preview['eta'] }}</span>
                                </div>
                                <div class="wa-bubble">
                                    <span class="ic">{!! $waIcon !!}</span>
                                    <div><b>{{ $preview['wa_title'] }} ✓</b><span>{{ $preview['wa_body'] }}</span></div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================= Screens ================= --}}
    <section class="section tint" id="screens" aria-labelledby="screens-title">
        <div class="shell">
            <div class="head reveal">
                <span class="eyebrow">{{ __('site.screens.eyebrow') }}</span>
                <h2 id="screens-title">{{ __('site.screens.title') }}</h2>
                <p>{{ __('site.screens.lead') }}</p>
            </div>
            <div class="screens">
                @foreach ($screens as $key => $path)
                    <figure class="reveal">
                        <div class="phone">
                            <img src="{{ asset($path) }}" alt="{{ __('site.screens.items.'.$key) }}" width="390" height="780" loading="lazy" decoding="async">
                        </div>
                        <figcaption>
                            <b>{{ __('site.screens.items.'.$key) }}</b>
                            <span>{{ __('site.screens.notes.'.$key) }}</span>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
            <div class="screen-dots" aria-hidden="true">
                @foreach ($screens as $key => $path)
                    <i @if ($loop->first) class="on" @endif></i>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================= FAQ ================= --}}
    <section class="section" id="faq" aria-labelledby="faq-title">
        <div class="shell">
            <div class="head reveal">
                <span class="eyebrow">{{ __('site.faq.eyebrow') }}</span>
                <h2 id="faq-title">{{ __('site.faq.title') }}</h2>
            </div>
            <div class="faq-wrap">
                <div class="faq">
                    @foreach (__('site.faq.items') as $item)
                        <details class="reveal" @if ($loop->first) open @endif>
                            <summary>{{ $item['q'] }}</summary>
                            <p>{{ $item['a'] }}</p>
                        </details>
                    @endforeach
                </div>
                <aside class="faq-help reveal">
                    <span class="tile">{!! $icon('chat') !!}</span>
                    <h3>{{ __('site.faq.help_title') }}</h3>
                    <p>{{ __('site.faq.help_body') }}</p>
                    <div class="faq-help-actions">
                        @if ($whatsappLink)
                            <a class="btn btn-wa" href="{{ $whatsappLink }}" target="_blank" rel="noopener">{!! $waIcon !!} {{ __('site.contact.whatsapp') }}</a>
                        @endif
                        <a class="btn btn-light" href="#contact">{{ __('site.faq.help_cta') }}</a>
                    </div>
                </aside>
            </div>
        </div>
    </section>

    {{-- ================= Contact: doctors reach the team directly ================= --}}
    <section class="section" id="contact" aria-labelledby="contact-title">
        <div class="shell">
            <div class="contact-card reveal">
                <div class="contact-head">
                    <span class="eyebrow">{{ __('site.contact.eyebrow') }}</span>
                    <h2 id="contact-title">{{ __('site.contact.title') }}</h2>
                    <p>{{ __('site.contact.lead') }}</p>
                </div>

                @if ($whatsappLink || $phone || $email)
                    <div class="channels">
                        @foreach (array_filter([
                            $whatsappLink ? ['key' => 'whatsapp', 'href' => $whatsappLink, 'icon' => $waIcon, 'copy' => $whatsappNational, 'copyLabel' => 'copy_number', 'external' => true] : null,
                            $phone ? ['key' => 'phone', 'href' => 'tel:'.$phone->e164, 'icon' => $icon('phone'), 'copy' => $phone->national(), 'copyLabel' => 'copy_number', 'external' => false] : null,
                            $email ? ['key' => 'email', 'href' => 'mailto:'.$email, 'icon' => $icon('mail'), 'copy' => $email, 'copyLabel' => 'copy_email', 'external' => false] : null,
                        ]) as $channel)
                            <div class="channel{{ $channel['key'] === 'whatsapp' ? ' channel-wa' : '' }}">
                                <span class="channel-ic">{!! $channel['icon'] !!}</span>
                                <b>{{ __('site.contact.'.$channel['key']) }}</b>
                                <small>{{ __('site.contact.'.$channel['key'].'_hint') }}</small>
                                <div class="channel-actions">
                                    <a class="btn channel-go" href="{{ $channel['href'] }}" @if ($channel['external']) target="_blank" rel="noopener" @endif>
                                        {{ __('site.contact.'.$channel['key'].'_action') }}
                                    </a>
                                    <button type="button" class="copy" data-copy="{{ $channel['copy'] }}"
                                            data-done="{{ __('site.contact.copied') }}"
                                            aria-label="{{ __('site.contact.'.$channel['copyLabel']) }}" title="{{ __('site.contact.'.$channel['copyLabel']) }}">
                                        <span class="copy-idle">{!! $icon('copy', 18) !!}</span>
                                        <span class="copy-done">{!! $icon('check', 18) !!}</span>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="copied-note" role="status" aria-live="polite"></p>
                @endif
            </div>
        </div>
    </section>
</main>

<footer>
    <div class="shell">
        <div class="foot">
            <div>
                <a class="logo" href="{{ route('root') }}">
                    <img src="{{ asset(config('clinic.brand.logo')) }}" alt="" width="38" height="38" loading="lazy">
                    <span>{{ $brand }}</span>
                </a>
                <p>{{ config('clinic.brand.slogan') }} — {{ __('site.hero.eyebrow') }}</p>
            </div>
            <div>
                <h4>{{ __('site.footer.links') }}</h4>
                <ul>
                    @foreach ($nav as $item)
                        <li><a href="#{{ $item }}">{{ __('site.nav.'.$item) }}</a></li>
                    @endforeach
                    @if ($showLogin)
                        <li><a href="{{ route('app.login') }}">{{ __('site.nav.login') }}</a></li>
                    @endif
                </ul>
            </div>
            <div>
                <h4>{{ __('site.footer.reach') }}</h4>
                <ul>
                    <li><a href="#contact">{{ __('site.contact.title') }}</a></li>
                    @if ($whatsappLink)<li><a href="{{ $whatsappLink }}" target="_blank" rel="noopener">{{ __('site.contact.whatsapp') }}</a></li>@endif
                    @if ($phone)<li><a href="tel:{{ $phone->e164 }}">{{ __('site.contact.phone') }}</a></li>@endif
                    @if ($email)<li><a href="mailto:{{ $email }}">{{ __('site.contact.email') }}</a></li>@endif
                    @foreach ($social as $network => $url)
                        <li><a href="{{ $url }}" target="_blank" rel="noopener">{{ $socialLabels[$network] ?? ucfirst($network) }}</a></li>
                    @endforeach
                </ul>
            </div>
        </div>
        <p class="rights">{{ __('site.footer.rights', ['year' => now()->year, 'brand' => $brand]) }}</p>
    </div>
</footer>

@if ($whatsappLink)
    <a class="wa-float" href="{{ $whatsappLink }}" target="_blank" rel="noopener" aria-label="{{ __('site.contact.whatsapp') }}">{!! $waIcon !!}</a>
@endif

<script>
    (function () {
        // The opening screen leaves once the page has loaded, and never before
        // the mark has been seen whole for a full second: 0.7s to arrive, then
        // 1s at rest. The CSS failsafe still clears it at 2.6s.
        var splash = document.getElementById('splash');
        if (splash && !document.documentElement.classList.contains('no-splash')) {
            var started = Date.now();
            var leave = function () {
                setTimeout(function () {
                    splash.classList.add('done');
                    setTimeout(function () { splash.remove(); }, 600);
                    try { sessionStorage.setItem('splash-seen', '1'); } catch (e) {}
                }, Math.max(0, 1700 - (Date.now() - started)));
            };
            if (document.readyState === 'complete') { leave(); } else { window.addEventListener('load', leave); }
        }

        // The header gains a line once the page moves under it.
        var top = document.getElementById('top');
        // The floating WhatsApp button waits until the hero's own is out of view.
        var hero = document.querySelector('.hero');
        var waFloat = document.querySelector('.wa-float');
        // ...and steps aside again over the contact section and the footer,
        // which carry their own — on a phone it would sit on their buttons.
        var contactArea = document.getElementById('contact');
        var onScroll = function () {
            top.classList.toggle('scrolled', window.scrollY > 8);
            if (waFloat) {
                var pastHero = window.scrollY > hero.offsetHeight * 0.7;
                var atContact = contactArea && contactArea.getBoundingClientRect().top < window.innerHeight * 0.85;
                waFloat.classList.toggle('shown', pastHero && !atContact);
            }
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        // The menu lights the section on screen; a hairline shows how far down you are.
        var bar = document.querySelector('.progress');
        var navLinks = Array.prototype.slice.call(document.querySelectorAll('.nav a'));
        var sections = navLinks.map(function (a) { return document.querySelector(a.getAttribute('href')); });
        var onScrollSpy = function () {
            var max = document.documentElement.scrollHeight - window.innerHeight;
            if (bar) { bar.style.transform = 'scaleX(' + (max > 0 ? Math.min(1, window.scrollY / max) : 0) + ')'; }
            var current = -1;
            sections.forEach(function (sec, i) { if (sec && sec.getBoundingClientRect().top < window.innerHeight * 0.35) { current = i; } });
            navLinks.forEach(function (a, i) { a.classList.toggle('active', i === current); });
        };
        onScrollSpy();
        window.addEventListener('scroll', onScrollSpy, { passive: true });

        // Dots under the screens follow the swipe.
        var strip = document.querySelector('.screens');
        var dots = document.querySelectorAll('.screen-dots i');
        if (strip && dots.length) {
            strip.addEventListener('scroll', function () {
                var figures = strip.querySelectorAll('figure');
                var centre = strip.getBoundingClientRect().left + strip.clientWidth / 2, best = 0, bestGap = Infinity;
                figures.forEach(function (f, i) {
                    var r = f.getBoundingClientRect(), gap = Math.abs(r.left + r.width / 2 - centre);
                    if (gap < bestGap) { bestGap = gap; best = i; }
                });
                dots.forEach(function (d, i) { d.classList.toggle('on', i === best); });
            }, { passive: true });
        }

        // Choosing a section from the phone menu closes it.
        var menu = document.querySelector('.menu');
        if (menu) {
            menu.querySelectorAll('a').forEach(function (a) {
                a.addEventListener('click', function () { menu.removeAttribute('open'); });
            });
        }

        // Sections ease in as they arrive. Without IntersectionObserver they
        // are simply shown.
        var items = document.querySelectorAll('.reveal');
        if (!('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('in'); });
        } else {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
                });
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
            items.forEach(function (el) { io.observe(el); });
        }

        // Role tabs, with arrow keys as a tablist expects. In RTL the
        // "next" tab is to the left.
        var tabs = Array.prototype.slice.call(document.querySelectorAll('[role="tab"]'));
        var select = function (tab) {
            tabs.forEach(function (t) {
                var on = t === tab;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
                var panel = document.getElementById(t.getAttribute('aria-controls'));
                panel.hidden = !on;
                if (on) { panel.classList.add('in'); }
            });
        };
        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () { select(tab); });
            tab.addEventListener('keydown', function (e) {
                var step = e.key === 'ArrowLeft' ? 1 : e.key === 'ArrowRight' ? -1 : 0;
                if (!step) { return; }
                var next = tabs[(i + step + tabs.length) % tabs.length];
                select(next);
                next.focus();
            });
        });

        // Copy a channel's number or address. The clipboard API needs a secure
        // page; the textarea route covers plain-http previews.
        var note = document.querySelector('.copied-note');
        var copyText = function (text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise(function (resolve, reject) {
                var area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                var ok = document.execCommand('copy');
                document.body.removeChild(area);
                ok ? resolve() : reject();
            });
        };
        document.querySelectorAll('.copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                copyText(btn.getAttribute('data-copy')).then(function () {
                    btn.classList.add('done');
                    if (note) { note.textContent = btn.getAttribute('data-done') + ' ✓'; }
                    clearTimeout(btn._t);
                    btn._t = setTimeout(function () {
                        btn.classList.remove('done');
                        if (note) { note.textContent = ''; }
                    }, 2000);
                }).catch(function () {
                    // Copying was refused (a locked-down browser): the action
                    // button beside it still opens the app, so stay quiet.
                });
            });
        });
    })();
</script>
</body>
</html>
