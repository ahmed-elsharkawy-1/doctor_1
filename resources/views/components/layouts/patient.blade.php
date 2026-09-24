{{--
    The public booking page's own shell.

    Standalone, like the doctor, tracking and review pages — it shares nothing
    with components/layouts/app.blade.php, which is the staff app's teal
    palette and Livewire's default layout. The tokens below are the same ones
    those three patient-facing pages use, copied rather than imported, which is
    the house pattern for this side of the app.

    It serves exactly one component, so the page's CSS lives here rather than
    inside the view: the markup stays readable and the media query that widens
    the page sits in one place.

    Not indexable. Only /{slug} is meant to be found.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @include('partials.brand-head')
    <title>{{ $title ?? config('clinic.brand.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    <style>
        /* Same tokens as the doctor page (Public/*). Light only in V1. */
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
            --success: #1B9E57;
            --success-bg: #E7F4EC;
            --success-ink: #14663A;
            --danger: #C0392B;
            --danger-bg: #FBECEA;
            --danger-ink: #8E2E22;
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
            /* Room for the fixed action bar; released once it unpins. */
            padding-bottom: 132px;
        }

        a { color: inherit; text-decoration: none; }
        button { font: inherit; color: inherit; }

        .wrap { width: min(30rem, 100% - 32px); margin: 0 auto; }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
        }

        .card-lift { border: 0; box-shadow: var(--shadow); }

        .stack { display: flex; flex-direction: column; gap: 14px; }

        .muted { color: var(--muted); }
        .h1 { font-size: 22px; font-weight: 800; line-height: 1.3; margin: 0; }
        .h2 { font-size: 18px; font-weight: 800; margin: 0; }
        .label { font-size: 13px; font-weight: 700; color: var(--ink-soft); }
        .hint { font-size: 12.5px; color: var(--muted); line-height: 1.6; }

        /* Buttons ---------------------------------------------------------- */
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 52px;
            border: 0;
            border-radius: 15px;
            background: var(--surface);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
        }

        .btn-primary { background: var(--primary); color: #fff; }
        .btn-quiet { background: var(--surface); color: var(--muted); min-height: 44px; font-size: 14px; }
        .btn-wa { background: var(--whatsapp); color: #fff; min-height: 48px; font-size: 15px; }
        .btn-outline { background: var(--surface); border: 1px solid var(--line-strong); color: var(--ink); min-height: 48px; font-size: 15px; }
        .btn:disabled { background: var(--line-strong); color: #fff; cursor: not-allowed; }

        /* The page's own title row, above everything ------------------------ */
        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 0 12px;
        }
        .page-head h1 { margin: 0; font-size: 19px; font-weight: 800; }

        .icon-btn {
            flex: 0 0 auto;
            width: 38px; height: 38px;
            display: grid; place-items: center;
            border: 1px solid var(--line);
            border-radius: 50%;
            background: var(--surface);
            color: var(--ink-soft);
            cursor: pointer;
        }
        .icon-btn:disabled { opacity: .45; cursor: default; }

        /* Step of N, over one bar — the steps are few and named on screen,
           so a segment each said the same thing twice. */
        .step-note { font-size: 12px; font-weight: 600; color: var(--primary); text-align: start; }
        .rail { margin-top: 8px; height: 5px; border-radius: 3px; background: var(--line); overflow: hidden; }
        .rail i { display: block; height: 100%; border-radius: 3px; background: var(--primary); }

        /* The doctor, carried through every step --------------------------- */
        .who { display: flex; align-items: center; gap: 12px; }
        .who-face {
            flex: 0 0 auto;
            width: 56px; height: 56px;
            border-radius: 16px;
            background: var(--primary-50);
            object-fit: cover;
            display: grid; place-items: center;
            color: var(--primary); font-size: 22px; font-weight: 800;
            overflow: hidden;
        }
        .who-name { font-size: 16px; font-weight: 800; line-height: 1.3; }
        .who-role { margin-top: 2px; font-size: 12.5px; color: var(--muted); }

        /* The read-only week ------------------------------------------------ */
        .daylist { border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
        .dayrow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 13px;
            border-bottom: 1px solid var(--line);
        }
        .dayrow:last-child { border-bottom: 0; }
        .dayrow.is-today { background: var(--primary-50); }
        .dayrow.is-off { background: #FDF6EA; }
        .dayrow-when { display: flex; align-items: baseline; gap: 7px; font-size: 13.5px; }
        .dayrow-when b { font-weight: 800; }
        .dayrow-when span { color: var(--muted); font-size: 12.5px; }

        .tag {
            flex: 0 0 auto;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            white-space: nowrap;
        }
        .tag-now { background: var(--primary); color: #fff; }
        .tag-free { background: var(--primary-50); color: var(--primary); }
        .tag-full { background: #fff; border: 1px solid #E7B8B1; color: var(--danger); }
        .tag-off { background: #fff; border: 1px solid #F3E2C2; color: #7A5410; }

        /* Visit types, as a row that scrolls rather than a grid that wraps -- */
        .pills { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: none; }
        .pills::-webkit-scrollbar { display: none; }
        .pill {
            flex: 0 0 auto;
            padding: 10px 16px;
            border: 1px solid var(--line-strong);
            border-radius: 999px;
            background: var(--surface);
            font-size: 14px; font-weight: 700;
            white-space: nowrap;
            cursor: pointer;
        }
        .pill small { display: block; margin-top: 1px; font-size: 11px; font-weight: 600; color: var(--muted); }
        .pill[aria-pressed="true"] { background: var(--primary); border-color: var(--primary); color: #fff; }
        .pill[aria-pressed="true"] small { color: rgba(255, 255, 255, .84); }

        /* Days and slots --------------------------------------------------- */
        .days { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: none; }
        .days::-webkit-scrollbar { display: none; }

        .day {
            flex: 0 0 auto;
            width: 62px;
            padding: 10px 0;
            display: flex; flex-direction: column; align-items: center; gap: 2px;
            border: 1px solid var(--line); border-radius: var(--radius);
            background: var(--surface);
            cursor: pointer;
        }
        .day-name { font-size: 11px; font-weight: 600; color: var(--faint); }
        .day-num { font-size: 20px; font-weight: 800; line-height: 1; }
        .day-note {
            display: flex; align-items: center; justify-content: center;
            min-height: 13px;
            font-size: 10px; font-weight: 700; color: var(--success);
        }

        /* A dot rather than a number: how many are left matters far less than
           whether there is anything at all. */
        .day-dot {
            display: block;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--success);
        }

        .day.is-full { border-color: #E7B8B1; }
        .day.is-full .day-note { color: var(--danger); }
        .day.is-off { background: #FDF6EA; border-color: #F3E2C2; }
        .day.is-off .day-note { color: #7A5410; }
        .day.is-off .day-num { color: #7A5410; }

        .day[aria-pressed="true"] {
            background: var(--primary); border-color: var(--primary);
            box-shadow: 0 6px 14px rgba(24, 95, 165, .24);
        }
        .day[aria-pressed="true"] .day-name,
        .day[aria-pressed="true"] .day-note { color: rgba(255, 255, 255, .84); }
        .day[aria-pressed="true"] .day-num { color: #fff; }
        .day[aria-pressed="true"] .day-dot { background: #fff; }
        .day:disabled { cursor: not-allowed; }
        .day:disabled:not(.is-off) { background: var(--surface-2); opacity: .7; }
        .day:disabled:not(.is-off) .day-num { color: var(--faint); }

        .month { font-size: 12.5px; color: var(--muted); }

        .slots { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .slot {
            min-height: 46px;
            border: 1px solid var(--line); border-radius: 13px;
            background: var(--surface);
            font-size: 14.5px; font-weight: 600;
            cursor: pointer;
        }
        .slot[aria-pressed="true"] {
            background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 700;
            box-shadow: 0 6px 14px rgba(24, 95, 165, .24);
        }
        .slot:disabled {
            background: var(--surface-2); color: var(--faint);
            text-decoration: line-through; cursor: not-allowed;
        }

        /* Visit types ------------------------------------------------------ */
        .types { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .type {
            text-align: start;
            padding: 11px 12px;
            border: 1px solid var(--line); border-radius: 14px;
            background: var(--surface);
            cursor: pointer;
        }
        .type-name { font-size: 14px; font-weight: 700; }
        .type-meta { margin-top: 2px; font-size: 11.5px; color: var(--muted); }
        .type[aria-pressed="true"] { background: var(--primary); border-color: var(--primary); }
        .type[aria-pressed="true"] .type-name { color: #fff; }
        .type[aria-pressed="true"] .type-meta { color: rgba(255, 255, 255, .84); }

        /* Fields ----------------------------------------------------------- */
        .field { display: flex; flex-direction: column; gap: 7px; }
        .field input {
            width: 100%;
            height: 52px;
            padding: 0 15px;
            border: 1.5px solid var(--line); border-radius: 14px;
            background: var(--surface);
            font-size: 15.5px; color: var(--ink);
            outline: none;
        }
        .field input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(24, 95, 165, .10); }
        .field .err { font-size: 12.5px; color: var(--danger); }

        /* One box per digit — drawn, not four inputs.
           Four separate fields need per-box key handling to be usable at all
           (advance on type, retreat on backspace, land a pasted code across
           all of them), and SMS autofill only ever targets one element. So
           the boxes are divs and a single transparent input lies over them:
           paste, autofill and `maxlength` behave exactly as on any field,
           and the digits are mirrored into the boxes. */
        .code-boxes { position: relative; display: flex; gap: 10px; direction: ltr; }

        .code-box {
            flex: 1 1 0;
            height: 62px;
            display: flex; align-items: center; justify-content: center;
            border: 1.5px solid var(--line); border-radius: 14px;
            background: var(--surface);
            font-size: 26px; font-weight: 700; color: var(--ink);
        }
        .code-box.is-filled { border-color: var(--line-strong); }

        /* The caret has nowhere to show through an invisible input, so the box
           it would sit in is marked instead — but only while the field is
           actually focused. */
        .code-boxes:focus-within .code-box.is-next {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(24, 95, 165, .10);
        }

        /* Covers the whole row: a tap anywhere lands in the one real field.
           16px keeps iOS from zooming the page on focus. */
        .code-entry {
            position: absolute; inset: 0;
            width: 100%; height: 100%;
            border: 0; padding: 0; background: none;
            font-size: 16px; color: transparent;
            caret-color: transparent;
            outline: none;
        }

        /* Notes ------------------------------------------------------------ */
        .note { display: flex; gap: 10px; align-items: flex-start; border-radius: 14px; padding: 12px 14px; font-size: 12.5px; line-height: 1.6; }
        .note svg { flex: 0 0 auto; margin-top: 2px; }
        .note-info { background: var(--primary-50); color: var(--ink-soft); }
        .note-ok { background: var(--success-bg); color: var(--success-ink); }
        .note-bad { background: var(--danger-bg); color: var(--danger-ink); }
        .note-plain { background: var(--surface); border: 1px solid var(--line); color: var(--muted); }
        .note-dashed { background: var(--surface); border: 1px dashed var(--line-strong); }

        /* The action bar --------------------------------------------------- */
        .bar {
            position: fixed;
            inset-inline: 0;
            bottom: 0;
            z-index: 20;
            background: var(--surface);
            border-top: 1px solid var(--line);
            box-shadow: 0 -6px 18px rgba(20, 60, 100, .05);
            padding: 10px 16px calc(16px + env(safe-area-inset-bottom));
        }
        .bar-inner { width: min(30rem, 100% - 0px); margin: 0 auto; display: flex; flex-direction: column; gap: 9px; }
        .bar-sum { display: flex; align-items: center; justify-content: space-between; font-size: 13px; }
        .hold {
            display: flex; align-items: center; justify-content: center; gap: 7px;
            background: var(--primary-50); border-radius: 11px; padding: 7px 12px;
            font-size: 12.5px; font-weight: 600; color: var(--ink-soft);
        }

        .ltr { direction: ltr; unicode-bidi: isolate; }

        /* Wider screens: the same page, more room. Not a second design —
           the column widens, the grids gain columns, and the action bar stops
           being pinned because there is no scroll pressure. */
        @media (min-width: 900px) {
            body { padding-bottom: 40px; }
            .wrap { width: min(680px, 100% - 48px); }
            .slots { grid-template-columns: repeat(5, minmax(0, 1fr)); }
            .days { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); overflow: visible; }
            .day { width: auto; }
            .field input { max-width: 420px; }

            .bar {
                position: static;
                margin-top: 16px;
                border: 1px solid var(--line);
                border-radius: var(--radius);
                box-shadow: none;
                padding: 14px 16px;
            }
            .bar-inner { width: 100%; }
        }
    </style>

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @livewireScripts

    {{--
        Mirrors the verification code into its boxes as it is typed.

        Purely cosmetic: the value lives on the one real input and reaches the
        server on submit, as any field does. Without this the boxes simply fill
        a round trip later, from the server-rendered digits — which is why the
        code stage is still usable with this script blocked.

        Delegated from the document and re-run after every Livewire morph, so
        it needs no per-element wiring and survives the component re-rendering
        under it.
    --}}
    <script>
        (function () {
            function paint(input) {
                // Digits only: a pasted "code: 1234" should not fill a box
                // with a colon, and the field is numeric everywhere else.
                var digits = (input.value || '').replace(/\D/g, '')
                    .slice(0, Number(input.getAttribute('maxlength')) || 4);

                if (digits !== input.value) {
                    input.value = digits;
                }

                var boxes = input.parentNode.querySelectorAll('.code-box');

                for (var i = 0; i < boxes.length; i++) {
                    boxes[i].textContent = digits.charAt(i);
                    boxes[i].classList.toggle('is-filled', i < digits.length);
                    boxes[i].classList.toggle('is-next', i === digits.length);
                }
            }

            function paintAll() {
                document.querySelectorAll('[data-code-boxes] .code-entry').forEach(paint);
            }

            document.addEventListener('input', function (event) {
                if (event.target.classList.contains('code-entry')) {
                    paint(event.target);
                }
            });

            document.addEventListener('DOMContentLoaded', paintAll);
            document.addEventListener('livewire:initialized', function () {
                Livewire.hook('morph.updated', paintAll);
            });
        })();
    </script>
</body>
</html>
