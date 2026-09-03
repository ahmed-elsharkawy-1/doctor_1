{{-- The platform root. Clinics live at /{slug}; this is only a signpost. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #eef2f7;
            --card: #ffffff;
            --ink: #16202e;
            --muted: #6b7a8d;
            --line: #e2e8f0;
            --brand: #0f766e;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, system-ui, sans-serif;
            line-height: 1.7;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 2rem 1.75rem;
            margin: 1rem;
            max-width: 24rem;
            text-align: center;
        }

        h1 { margin: 0 0 0.35rem; font-size: 1.25rem; }
        p { margin: 0 0 1.25rem; color: var(--muted); }

        a.btn {
            display: block;
            background: var(--brand);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            padding: 0.85rem;
            border-radius: 0.7rem;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1620;
                --card: #18222f;
                --ink: #e8eef5;
                --muted: #93a3b6;
                --line: #26333f;
                --brand: #4db6a5;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('app.root.title') }}</h1>
        <p>{{ __('app.root.lead') }}</p>
        <a class="btn" href="{{ route('app.login') }}">{{ __('app.root.sign_in') }}</a>
    </div>
</body>
</html>
