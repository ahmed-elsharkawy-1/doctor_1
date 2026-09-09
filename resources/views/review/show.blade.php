@php
    $doctorName = $doctor?->name ?? $clinic->name;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('review.title') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    <style>
        /* Same tokens as the tracking and doctor pages (Public/*). */
        :root {
            color-scheme: light;
            --ink: #132433;
            --muted: #5F6F80;
            --faint: #8B9AAA;
            --primary: #185FA5;
            --primary-50: #EEF4FB;
            --success: #1B9E57;
            --success-bg: #E7F4EC;
            --surface: #FFFFFF;
            --surface-2: #F6F9FC;
            --line: #E5ECF3;
            --line-strong: #CFDAE6;
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
            line-height: 1.6;
            -webkit-text-size-adjust: 100%;
        }

        h1, h2, p { margin: 0; }

        .topbar { background: var(--primary); height: 54px; }
        .wrap { width: min(30rem, 100% - 24px); margin: 0 auto; padding-bottom: 40px; }

        .card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 18px;
            margin-bottom: 12px;
        }

        /* who the visit was with */
        .doctor { margin-top: -22px; display: flex; align-items: center; gap: 12px; }
        .doctor img, .doctor .initial {
            width: 48px; height: 48px;
            flex: 0 0 auto;
            border-radius: 13px;
            object-fit: cover;
            background: var(--primary-50);
        }
        .doctor .initial { display: grid; place-items: center; color: var(--primary); font-size: 20px; font-weight: 800; }
        .doctor h2 { font-size: 16px; font-weight: 800; }
        .doctor .role { color: var(--muted); font-size: 13px; }

        h1 { font-size: 20px; font-weight: 800; }
        .lead { color: var(--muted); font-size: 13.5px; margin-top: 4px; }

        .section-label { font-weight: 700; margin-bottom: 10px; }

        /* the three choices */
        .choices { display: grid; gap: 0; }

        .choice { display: block; cursor: pointer; }
        .choice input { position: absolute; opacity: 0; pointer-events: none; }

        .choice .face {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 14px 4px;
            border-bottom: 1px solid var(--line);
            font-weight: 700;
        }

        .choice:last-child .face { border-bottom: 0; }

        .choice .dot {
            width: 20px; height: 20px;
            flex: 0 0 auto;
            border-radius: 999px;
            border: 2px solid var(--line-strong);
            display: grid;
            place-items: center;
        }

        .choice input:checked + .face { color: var(--primary); }
        .choice input:checked + .face .dot { border-color: var(--primary); }
        .choice input:checked + .face .dot::after {
            content: "";
            width: 10px; height: 10px;
            border-radius: 999px;
            background: var(--primary);
        }
        .choice input:focus-visible + .face .dot { outline: 2px solid var(--primary); outline-offset: 2px; }

        textarea {
            width: 100%;
            min-height: 104px;
            padding: 11px 13px;
            border: 1px solid var(--line-strong);
            border-radius: 12px;
            background: var(--surface-2);
            color: var(--ink);
            font: inherit;
            resize: vertical;
        }

        textarea:focus { outline: 2px solid var(--primary); outline-offset: 1px; }
        .counter { text-align: start; color: var(--faint); font-size: 12px; margin-top: 6px; }

        .btn {
            display: block;
            width: 100%;
            border: 0;
            border-radius: 12px;
            background: var(--primary);
            color: #fff;
            padding: 14px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }

        .btn:hover { filter: brightness(.95); }

        .err {
            background: #FBECEA;
            color: #8E2F23;
            border-radius: 12px;
            padding: 10px 13px;
            font-size: 13px;
            margin-bottom: 12px;
        }

        /* result + refusal states */
        .state { text-align: center; padding: 26px 18px; }

        .state .mark {
            display: grid;
            place-items: center;
            width: 68px; height: 68px;
            margin: 0 auto 14px;
            border-radius: 999px;
            background: var(--success-bg);
            color: var(--success);
        }

        .state.neutral .mark { background: var(--primary-50); color: var(--primary); }
        .state h1 { font-size: 19px; }
        .state .lead { margin-top: 6px; }

        .verdict {
            display: inline-block;
            margin-top: 14px;
            border-radius: 999px;
            background: var(--primary-50);
            color: var(--primary);
            padding: 6px 16px;
            font-weight: 800;
        }

        .said {
            margin-top: 12px;
            background: var(--surface-2);
            border-radius: 12px;
            padding: 12px 14px;
            color: var(--muted);
            font-size: 13.5px;
            text-align: start;
        }
    </style>
</head>
<body>

<div class="topbar"></div>

<div class="wrap">

    <section class="card doctor">
        @if ($doctor?->avatarUrl())
            <img src="{{ $doctor->avatarUrl() }}" alt="{{ $doctorName }}" loading="lazy">
        @else
            <div class="initial" aria-hidden="true">{{ mb_substr(preg_replace('/^د\.\s*/u', '', $doctorName), 0, 1) }}</div>
        @endif

        <div>
            <h2>{{ $doctorName }}</h2>
            @if ($doctor?->title || $clinic->specialty)
                <p class="role">{{ $doctor?->title ?: $clinic->specialty?->name }}</p>
            @endif
        </div>
    </section>

    @if ($review !== null)
        {{-- Already answered: show it back rather than inviting a second one. --}}
        <section class="card state">
            <div class="mark">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <h1>{{ __('review.thanks_title') }}</h1>
            <p class="lead">{{ __('review.thanks_lead') }}</p>

            <div class="verdict">{{ __('review.your_rating') }}: {{ $review->rating->label() }}</div>

            @if ($review->comment)
                <p class="said">{{ $review->comment }}</p>
            @endif
        </section>

    @elseif ($unavailable)
        <section class="card state neutral">
            <div class="mark">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
            </div>
            <h1>{{ __('review.unavailable_title') }}</h1>
            <p class="lead">{{ __('review.unavailable_lead') }}</p>
        </section>

    @elseif (! $reviewable)
        <section class="card state neutral">
            <div class="mark">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            </div>
            <h1>{{ __('review.not_done_title') }}</h1>
            <p class="lead">{{ __('review.not_done_lead') }}</p>
        </section>

    @else
        <section class="card">
            <h1>{{ __('review.title') }}</h1>
            <p class="lead">{{ __('review.lead') }}</p>
        </section>

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('booking.review.store', $booking->tracking_token) }}">
            @csrf

            <section class="card">
                <p class="section-label">{{ __('review.choose') }}</p>

                <div class="choices">
                    @foreach ($ratings as $rating)
                        <label class="choice">
                            <input type="radio" name="rating" value="{{ $rating->value }}"
                                   @checked(old('rating') === $rating->value)>
                            <span class="face">
                                <span class="dot"></span>
                                {{ $rating->label() }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="card">
                <p class="section-label">{{ __('review.comment') }}</p>
                <textarea name="comment" id="comment" maxlength="{{ $commentMax }}"
                          placeholder="{{ __('review.comment_placeholder') }}">{{ old('comment') }}</textarea>
                <div class="counter"><span id="left">{{ $commentMax }}</span></div>
            </section>

            <button type="submit" class="btn">{{ __('review.submit') }}</button>
        </form>

        <script>
            // Characters remaining, mirroring the maxlength the field enforces.
            const box = document.getElementById('comment');
            const left = document.getElementById('left');
            const max = {{ $commentMax }};

            const tick = () => { left.textContent = max - box.value.length; };
            box.addEventListener('input', tick);
            tick();
        </script>
    @endif

</div>
</body>
</html>
