{{--
    A waiting-room TV (ELA-19): one branch's queue for today, read from across
    the room. Large type, high contrast, no interaction. Codes and first names
    only.
--}}
@php
    $label = function ($booking) {
        $first = explode(' ', trim((string) $booking->patient?->name))[0] ?? '';

        return trim(($booking->patient?->code ? $booking->patient->code.' · ' : '').$first);
    };
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="{{ $refreshSeconds }}">
    <title>{{ $branch->name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@500;700;800&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: dark; --bg: #0c1b2a; --card: #13283c; --ink: #f2f6fa; --muted: #9fb3c6; --accent: #3fb6a8; --now: #f5b942; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: var(--bg); color: var(--ink); font-family: Tajawal, system-ui, sans-serif; }
        body { display: grid; grid-template-rows: auto 1fr; padding: 3vh 4vw; gap: 3vh; }
        header { display: flex; justify-content: space-between; align-items: baseline; gap: 2vw; }
        header h1 { margin: 0; font-size: clamp(28px, 4.2vw, 64px); font-weight: 800; }
        header .sub { color: var(--muted); font-size: clamp(16px, 1.8vw, 28px); }
        header .clock { font-size: clamp(28px, 4vw, 60px); font-weight: 700; font-variant-numeric: tabular-nums; direction: ltr; }
        main { display: grid; grid-template-columns: 1fr 1.3fr; gap: 3vw; min-height: 0; }
        .panel { background: var(--card); border-radius: 2vw; padding: 3vh 2.5vw; min-height: 0; overflow: hidden; }
        .panel h2 { margin: 0 0 2vh; font-size: clamp(20px, 2.4vw, 38px); color: var(--muted); font-weight: 700; }
        .now .name { font-size: clamp(40px, 7vw, 120px); font-weight: 800; color: var(--now); line-height: 1.1; }
        .now .empty { font-size: clamp(24px, 3vw, 48px); color: var(--muted); }
        ol { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.4vh; }
        li { display: flex; gap: 1.5vw; align-items: center; font-size: clamp(24px, 3.4vw, 56px); font-weight: 700; padding: 1vh 1vw; border-radius: 1vw; background: rgba(255,255,255,.04); }
        li .n { width: 2.2em; text-align: center; color: var(--accent); }
        .count { margin-top: 2vh; color: var(--muted); font-size: clamp(18px, 2vw, 30px); }
        .idle { grid-column: 1 / -1; display: grid; place-content: center; text-align: center; gap: 2vh; }
        .idle .big { font-size: clamp(36px, 6vw, 96px); font-weight: 800; }
        .idle .small { color: var(--muted); font-size: clamp(20px, 2.4vw, 40px); }
        @media (max-aspect-ratio: 1/1) { main { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>🏥 {{ $branch->name }}</h1>
            <div class="sub">{{ $clinic->doctor?->name ?? $clinic->name }}</div>
        </div>
        <div class="clock">{{ \App\Support\Wire::time($now)['display'] }}</div>
    </header>

    <main>
        @if ($idle)
            <div class="idle">
                <div class="big">{{ __('displays.idle_title') }}</div>
                @if ($days = $branch->workingDaysLabel())
                    <div class="small">{{ __('displays.idle_days', ['days' => $days]) }}</div>
                @endif
            </div>
        @else
            <section class="panel now">
                <h2>{{ __('displays.with_doctor') }}</h2>
                @forelse ($with_doctor as $booking)
                    <div class="name">{{ $label($booking) }}</div>
                @empty
                    <div class="empty">{{ __('displays.nobody_in') }}</div>
                @endforelse
            </section>

            <section class="panel">
                <h2>{{ __('displays.next') }}</h2>
                @if ($next->isEmpty())
                    <div class="now"><div class="empty">{{ __('displays.nobody_waiting') }}</div></div>
                @else
                    <ol>
                        @foreach ($next as $i => $booking)
                            <li><span class="n">{{ $i + 1 }}</span><span>{{ $label($booking) }}</span></li>
                        @endforeach
                    </ol>
                    @if ($waiting > $next->count())
                        <div class="count">{{ __('displays.more_waiting', ['count' => $waiting - $next->count()]) }}</div>
                    @endif
                @endif
            </section>
        @endif
    </main>
</body>
</html>
