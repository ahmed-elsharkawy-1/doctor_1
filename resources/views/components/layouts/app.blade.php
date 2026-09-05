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
    {{ $slot }}
    @livewireScripts
</body>
</html>
