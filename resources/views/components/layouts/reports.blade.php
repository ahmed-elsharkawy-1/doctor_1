{{--
    The doctor's reports pages. The patient pages' look — same tokens, brand
    bar and staging banner — without Livewire: these pages only read.

    Phone first. The doctor opens this from a WhatsApp message, so every block
    is a full-width card and nothing depends on hover.
--}}
@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    @include('partials.brand-head')
    <title>{{ $title ?? __('reports.page.title') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            color-scheme: light;
            --ink: #132433;
            --muted: #5F6F80;
            --primary: #185FA5;
            --primary-50: #EEF4FB;
            --success: #1B9E57;
            --danger: #C0392B;
            --surface: #FFFFFF;
            --surface-2: #F6F9FC;
            --line: #E5ECF3;
            --bg: #EEF2F7;
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
            line-height: 1.5;
        }

        .rp-wrap { max-width: 720px; margin: 0 auto; padding: 16px; }
        .rp-card { background: var(--surface); border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 16px; margin-bottom: 14px; }
        .rp-card h2 { font-size: 1rem; margin: 0 0 12px; }
        .rp-muted { color: var(--muted); font-size: .88rem; }
        .rp-btn { display: inline-block; border: 0; border-radius: 12px; padding: 12px 16px; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; }
        .rp-btn-primary { background: var(--primary); color: #fff; width: 100%; }
        .rp-btn-quiet { background: var(--surface-2); color: var(--ink); padding: 8px 12px; font-size: .85rem; }
    </style>
</head>
<body>
@include('partials.test-site-banner')
@include('partials.brand-bar')

<main class="rp-wrap">
    {{ $slot }}
</main>
</body>
</html>
