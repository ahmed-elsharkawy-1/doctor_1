<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? config('app.name') }}</title>
    <style>
        :root {
            --bg: #eef2f7;
            --card: #ffffff;
            --ink: #16202e;
            --muted: #6b7a8d;
            --line: #e2e8f0;
            --brand: #0f766e;
            --brand-soft: #e6f4f1;
            --warn: #b45309;
            --warn-soft: #fef6e7;
            --danger: #b42318;
            --danger-soft: #fdeceb;
            --shadow: 0 1px 2px rgb(16 32 46 / 6%);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, system-ui, sans-serif;
            line-height: 1.6;
        }

        /* Mobile first: the reception desk may be a phone or a monitor, so the
           same layout simply widens rather than forking into two designs. */
        .wrap { max-width: 64rem; margin: 0 auto; padding: 0 1rem 4rem; }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 0;
        }

        /* The app bar. On a phone the links scroll sideways rather than
           wrapping into a wall; on a monitor everything sits on one line. */
        .appbar {
            background: var(--card);
            border-bottom: 1px solid var(--line);
            margin-bottom: 1rem;
        }

        .appbar .inner {
            max-width: 64rem;
            margin: 0 auto;
            padding: 0.6rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .appbar .who { line-height: 1.3; }
        .appbar .who b { display: block; font-size: 0.95rem; }
        .appbar .who small { color: var(--muted); font-size: 0.8rem; }

        .appbar .nav {
            order: 3;
            width: 100%;
            display: flex;
            gap: 0.15rem;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .appbar .nav::-webkit-scrollbar { display: none; }

        .appbar .nav a {
            white-space: nowrap;
            padding: 0.4rem 0.7rem;
            border-radius: 0.5rem;
            color: var(--muted);
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .appbar .nav a:hover { color: var(--brand); }

        .appbar .nav a[aria-current="page"] {
            background: var(--brand-soft);
            color: var(--brand);
        }

        .appbar form { margin-inline-start: auto; }

        @media (min-width: 48rem) {
            .appbar .nav { order: 0; width: auto; flex: 1; }
            .appbar form { margin-inline-start: 0; }
        }

        .topbar h1 { margin: 0; font-size: 1.05rem; font-weight: 700; }
        .topbar .who { color: var(--muted); font-size: 0.85rem; }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 0.9rem;
            box-shadow: var(--shadow);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            border: 1px solid var(--line);
            background: var(--card);
            color: var(--ink);
            border-radius: 0.6rem;
            padding: 0.5rem 0.9rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover { border-color: var(--brand); color: var(--brand); }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        .btn-primary:hover { color: #fff; opacity: 0.92; }
        .btn-danger { color: var(--danger); border-color: var(--danger-soft); }
        .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.85rem; }

        .flash {
            border-radius: 0.7rem;
            padding: 0.7rem 1rem;
            margin-bottom: 0.85rem;
            font-weight: 600;
        }

        .flash-ok { background: var(--brand-soft); color: var(--brand); }
        .flash-err { background: var(--danger-soft); color: var(--danger); }

        .pill {
            display: inline-block;
            border-radius: 999px;
            padding: 0.1rem 0.65rem;
            font-size: 0.8rem;
            font-weight: 700;
            background: var(--brand-soft);
            color: var(--brand);
            white-space: nowrap;
        }

        .pill-warn { background: var(--warn-soft); color: var(--warn); }
        .pill-off { background: var(--danger-soft); color: var(--danger); }

        .muted { color: var(--muted); }

        .subnav {
            display: flex;
            gap: 4px;
            overflow-x: auto;
            margin-bottom: 14px;
            border-bottom: 1px solid var(--line);
        }

        .subnav a {
            white-space: nowrap;
            padding: 8px 12px;
            margin-bottom: -1px;
            border-bottom: 3px solid transparent;
            color: var(--muted);
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
        }

        .subnav a[aria-current="page"] { color: var(--brand-dark); border-bottom-color: var(--brand); }

        .field { margin-bottom: 0.9rem; }
        .field label { display: block; font-weight: 600; margin-bottom: 0.3rem; }

        .field input[type="text"],
        .field input[type="number"],
        .field input[type="date"],
        .field input[type="time"] {
            padding: 0.55rem 0.75rem;
            border: 1px solid var(--line);
            border-radius: 0.6rem;
            background: var(--card);
            color: var(--ink);
            font: inherit;
        }

        .field input[type="text"], .field input[type="number"] { width: 100%; }
        .field small { display: block; margin-top: 0.3rem; font-size: 0.8rem; }
        .err { color: var(--danger); font-size: 0.85rem; margin-top: 0.25rem; }

        .chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }

        .chip {
            border: 1px solid var(--line);
            background: var(--card);
            color: var(--ink);
            border-radius: 0.6rem;
            padding: 0.45rem 0.8rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .chip[aria-pressed="true"] { background: var(--brand); border-color: var(--brand); color: #fff; }

        /* Day stepper and empty state — the queue and the postpone screen are
           both "a clinic day", so they share one look. */
        .daybar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.85rem;
        }

        .daybar .day { font-weight: 700; }

        .empty { padding: 2.5rem 1rem; text-align: center; color: var(--muted); }

        @media (min-width: 48rem) {
            .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
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
                --warn: #e0a458;
                --warn-soft: #2e2517;
                --danger: #e2857c;
                --danger-soft: #33201e;
                --shadow: none;
            }
        }
    </style>
    @livewireStyles
</head>
<body>
    @auth
        @include('app.partials.navigation')
    @endauth

    {{ $slot }}
    @livewireScripts
</body>
</html>
