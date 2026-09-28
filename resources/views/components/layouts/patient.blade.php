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
            /* The action colour for this flow. Same green as the doctor's
               page, and deliberately not --primary: nearly every surface here
               is blue — the brand bar, the day rows, the selected slot, the
               tags — so a blue button is one more blue thing. The green is the
               only element on the page that is not, which is what makes it
               read as the thing to press. */
            --cta: #1FAF54;
            --cta-dark: #179044;
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
            /* Room for the fixed action bar; released once it unpins.
               Measured rather than guessed: the bar is a different height on
               every stage — tallest on the slot screen, where it carries three
               review rows and the hold notice — and a fixed figure sized for
               one of them buries the bottom of the others. The fallback is
               only for the instant before the script runs. */
            padding-bottom: calc(var(--bar-h, 132px) + 18px);
        }

        a { color: inherit; text-decoration: none; }
        button { font: inherit; color: inherit; }

        .wrap { width: min(30rem, 100% - 32px); margin: 0 auto; padding-bottom: 32px; }

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

        .btn-primary { background: var(--cta); color: #fff; }
        .btn-primary:active { background: var(--cta-dark); }
        .btn-quiet { background: var(--surface); color: var(--muted); min-height: 44px; font-size: 14px; }
        /* One filled button per screen.
           Two solid buttons stacked ask the same question twice: a screen has
           one thing it wants you to do, and everything else is a way out. So
           the filled treatment is reserved for that one thing and every other
           action is outlined.

           Outlined in the WhatsApp green rather than the neutral grey of
           .btn-outline, so it still reads as "message the clinic" at a glance
           — recognisable, but plainly the lesser of the two.

           Note this is the patient flow's own .btn-wa. The doctor's landing
           page declares its own, still filled, and rightly so: there WhatsApp
           is the main thing on offer. Here it is the alternative to it. */
        .btn-wa {
            background: var(--surface);
            border: 1px solid var(--whatsapp);
            color: var(--whatsapp);
            min-height: 48px;
            font-size: 15px;
        }
        .btn-outline { background: var(--surface); border: 1px solid var(--line-strong); color: var(--ink); min-height: 48px; font-size: 15px; }
        .btn:disabled { background: var(--line-strong); color: #fff; cursor: not-allowed; }

        /* The page's own title row, above everything ------------------------ */


        /* Step of N, over one bar — the steps are few and named on screen,
           so a segment each said the same thing twice. */
        .step-note { font-size: 12px; font-weight: 600; color: var(--primary); text-align: start; }
        .rail { margin-top: 8px; height: 5px; border-radius: 3px; background: var(--line); overflow: hidden; }
        .rail i { display: block; height: 100%; border-radius: 3px; background: var(--primary); }

        /* The doctor, carried through every step --------------------------- */
        /* The first card on the page, so it is the one that meets the header.
           Lifted by the same 40px the brand bar reserves via its `overlap`
           argument, and by the same amount the doctor-card component lifts itself on the
           tracking and doctor pages — three pages, one silhouette. Change the
           lift here and change the `overlap` passed to the partial to match. */
        .who {
            position: relative;
            margin-top: -40px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 0;
            box-shadow: var(--shadow);
        }
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

        /* The read-only week -----------------------------------------------

           This block is information, and it has to look like information.

           It used to be a bordered list of evenly spaced rows with filled
           pills on the right — which is exactly how a menu is drawn, here and
           everywhere else. People tapped the days and nothing happened. The
           explanatory note underneath did not help, because a visual grammar
           beats a sentence every time.

           So: no container box, no full-bleed dividers, no tinted row, no
           pills. Hairline dashes between figures, the way a statement of
           account is set. The only filled, rounded, coloured thing left on the
           screen is the button at the bottom — which is the only thing there
           is to do.

           Deliberately NOT greyed out or disabled-looking, which was the
           obvious alternative: this screen exists to convince a stranger that
           appointments are available, and dimming the availability says the
           opposite of that. Quiet chrome, confident numbers. */

        .soonest { margin: 0; font-size: 13.5px; color: var(--ink-soft); }
        .soonest b { color: var(--ink); font-weight: 800; }

        .daysum { margin: 0; }
        .daysum-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 2px;
            font-size: 13.5px;
        }
        /* Dashed, and only between rows: a separator that measures rather than
           one that frames something tappable. */
        .daysum-row + .daysum-row { border-top: 1px dashed var(--line); }

        .daysum dt { display: flex; align-items: baseline; gap: 7px; font-weight: 800; }
        .daysum .daysum-date { font-weight: 600; font-size: 12.5px; color: var(--muted); }
        .daysum .daysum-today { font-weight: 700; font-size: 11.5px; color: var(--primary); }

        /* Plain text, not a badge. Green because the answer is good news. */
        .daysum dd { margin: 0; font-weight: 700; color: var(--success-ink); white-space: nowrap; }
        /* Two stretches in a day stack rather than run together, so "morning
           and evening" reads as two facts and not one long span. */
        /* No `direction: ltr` here. Each time is "9:00 ص" — digits then an
           Arabic marker — so forcing the line LTR makes the bidi algorithm
           reorder the parts and the marker lands against the wrong number.
           The <bdi> around each time isolates it; the pair then falls in the
           page's own direction, which is what reads correctly in Arabic. */
        .daysum-range { display: block; text-align: end; }
        .daysum-range + .daysum-range { margin-top: 2px; }
        .daysum dd.is-off { font-weight: 600; color: #7A5410; }
        .daysum dd.is-full { font-weight: 600; color: var(--danger); }


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
           16px keeps iOS from zooming the page on focus.

           Selected as `.field .code-boxes input` rather than `.code-entry` on
           purpose. `.field input` above is (0,1,1) and a lone class is (0,1,0),
           so a class selector loses to it on every property they share — the
           field's border, background, height and colour would all win and the
           invisible overlay would render as a second visible box above the
           digits. This is (0,2,1) and wins. */
        .field .code-boxes input.code-entry {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            margin: 0; padding: 0;
            border: 0; border-radius: 14px;
            background: none;
            font-size: 16px;
            color: transparent;
            caret-color: transparent;
            outline: none;
            /* Both of these are undoing `.field input` rules that would
               otherwise show through an element meant to be invisible:

               `max-width: 420px` in the desktop block caps form fields to a
               readable measure. Applied here it clamped the overlay to ~60% of
               the box row, so its right edge — and its focus ring — cut across
               the middle of the row.

               The focus ring is drawn with box-shadow, which a `border: 0`
               does nothing about. It only appeared once the field was focused,
               which is to say the moment somebody started typing. */
            max-width: none;
            box-shadow: none;
            /* Above the drawn boxes so the tap target is the whole row. */
            z-index: 1;
        }

        /* The verified patient, on the slot screen --------------------------

           Three parts on one row: a tick, the person, and the way to change
           them. The name and number stack because they are one fact about one
           person — side by side they read as two separate fields. */
        .who-verified {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            text-align: start;
            cursor: pointer;
            background: var(--surface);
        }

        .who-verified-tick {
            flex: 0 0 auto;
            width: 30px; height: 30px;
            display: grid; place-items: center;
            border-radius: 50%;
            background: var(--success-bg);
            color: var(--success);
        }

        /* min-width: 0 so a long name truncates instead of shoving the edit
           link off the end of the row. */
        .who-verified-who { flex: 1 1 auto; min-width: 0; display: block; }

        .who-verified-name {
            display: block;
            font-size: 14px; font-weight: 800; color: var(--ink);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .who-verified-phone {
            display: block;
            margin-top: 2px;
            font-size: 12.5px; font-weight: 600; color: var(--muted);
        }

        /* Bordered rather than bare text: on a card that is entirely tappable,
           a coloured word alone does not read as the thing to press. */
        .who-verified-edit {
            flex: 0 0 auto;
            padding: 5px 12px;
            border: 1px solid var(--line-strong);
            border-radius: 999px;
            font-size: 12.5px; font-weight: 700;
            color: var(--primary);
            background: var(--surface);
        }
        .who-verified:hover .who-verified-edit { border-color: var(--primary); }

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
        /* The final review, above the confirm button. Labelled rows, because
           a date, a time and a duration set as one line read as a single
           string rather than three things to check. */
        .review { margin: 0 0 2px; display: grid; gap: 7px; }
        .review > div { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
        .review dt { font-size: 12.5px; color: var(--muted); }
        .review dd { margin: 0; font-size: 14px; font-weight: 800; text-align: end; }
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

            /* The bar is a sibling of .wrap, not a child of it — it has to be,
               because on a phone it is pinned to the viewport. Once it stops
               being pinned it is just another block in the page, so it takes
               the column's width itself rather than spanning the body. Same
               measure as .wrap above; change one, change both. */
            .bar {
                position: static;
                width: min(680px, 100% - 48px);
                margin: 16px auto 0;
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

    @if (config('broadcasting.default') === 'pusher' && filled(config('broadcasting.connections.pusher.key')))
        {{--
            Live slot updates.

            Loaded from a CDN rather than bundled: this app has no Vite build
            and the staff side ships no JavaScript, so adding a build step for
            one script would be a new thing to maintain for every page.

            Entirely optional. With this blocked, or Pusher down, or the key
            unset, the page still books visits correctly — it just learns a
            slot went when it next asks, instead of the moment it happens.
            Nothing here enforces anything; the day lock does that.
        --}}
        <script src="https://js.pusher.com/8.4/pusher.min.js" crossorigin="anonymous"></script>
        <script>
            (function () {
                if (typeof Pusher === 'undefined') {
                    return;
                }

                var pusher = new Pusher(@json(config('broadcasting.connections.pusher.key')), {
                    cluster: @json(config('broadcasting.connections.pusher.options.cluster')),
                });

                var current = null;

                // The patient moves between days, so the channel we care about
                // changes under us. Re-read it after every Livewire render and
                // swap subscriptions when it has moved.
                function sync() {
                    var el = document.querySelector('[data-slots-channel]');
                    var wanted = el ? el.getAttribute('data-slots-channel') : null;

                    if (wanted === current) {
                        return;
                    }

                    if (current) {
                        pusher.unsubscribe(current);
                    }

                    current = wanted;

                    if (!current) {
                        return;
                    }

                    pusher.subscribe(current).bind('SlotsChanged', function () {
                        // The event says only which day moved. What that means
                        // for this patient depends on the visit type they have
                        // chosen, so we re-ask rather than guess.
                        if (window.Livewire) {
                            window.Livewire.dispatch('slots-changed');
                        }
                    });
                }

                document.addEventListener('livewire:initialized', function () {
                    sync();
                    Livewire.hook('morph.updated', sync);
                });
            })();
        </script>
    @endif

    {{--
        Keeps the page clear of the fixed action bar.

        The bar's height changes with the stage and with what the patient has
        chosen — picking a slot adds three rows of review to it — so the space
        the page has to leave underneath itself cannot be a constant. This
        measures the bar and publishes it as --bar-h, which body's padding
        reads.

        A ResizeObserver rather than a re-render hook: the bar also changes
        height when the viewport rotates or a long doctor name wraps, neither
        of which is a Livewire update.
    --}}
    <script>
        (function () {
            var bar = null;

            function measure() {
                bar = bar && bar.isConnected ? bar : document.querySelector('.bar');

                if (!bar) {
                    return;
                }

                document.documentElement.style.setProperty('--bar-h', bar.offsetHeight + 'px');
            }

            function watch() {
                measure();

                if (bar && window.ResizeObserver && !bar.__measured) {
                    bar.__measured = true;
                    new ResizeObserver(measure).observe(bar);
                }
            }

            document.addEventListener('DOMContentLoaded', watch);
            window.addEventListener('resize', measure);
            document.addEventListener('livewire:initialized', function () {
                watch();
                Livewire.hook('morph.updated', watch);
            });
        })();
    </script>

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

                // The server emptied the code — asking for a new one, or
                // changing the number. The field has to be emptied here too:
                // `wire:model` is deferred, so the input still holds what was
                // typed, and the mirror above would paint the old code straight
                // back over the cleared boxes.
                Livewire.on('code-cleared', function () {
                    document.querySelectorAll('[data-code-boxes] .code-entry').forEach(function (input) {
                        input.value = '';
                        paint(input);
                    });
                });
            });
        })();
    </script>
</body>
</html>
