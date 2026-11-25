{{--
    A waiting-room TV (ELA-19): one branch's queue for today, read from across
    the room. Large type, high contrast, no interaction. Codes and first names
    only. The page loads once; the board inside it is fetched again every few
    seconds (tv.board).
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $branch->name }}</title>
    <meta name="referrer" content="no-referrer">
    <style>
        :root { color-scheme: dark; --bg: #0c1b2a; --card: #13283c; --ink: #f2f6fa; --muted: #9fb3c6; --accent: #3fb6a8; --now: #f5b942; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: var(--bg); color: var(--ink); font-family: Tajawal, 'Noto Sans Arabic', 'Segoe UI', system-ui, sans-serif; }
        body { display: grid; grid-template-rows: auto 1fr; padding: 3vh 4vw; gap: 3vh; }
        header { display: flex; justify-content: space-between; align-items: baseline; gap: 2vw; }
        header h1 { margin: 0; font-size: clamp(28px, 6.7vmin, 64px); font-weight: 800; }
        header .sub { color: var(--muted); font-size: clamp(16px, 2.9vmin, 28px); }
        header .clock { font-size: clamp(28px, 6.4vmin, 60px); font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        main { display: grid; grid-template-columns: 1fr 1.3fr; gap: 3vw; min-height: 0; }
        .panel { background: var(--card); border-radius: 2vw; padding: 3vh 2.5vw; min-height: 0; overflow: hidden; }
        .panel h2 { margin: 0 0 2vh; font-size: clamp(20px, 3.8vmin, 38px); color: var(--muted); font-weight: 700; }
        .now .name { font-size: clamp(40px, 11.2vmin, 120px); font-weight: 800; color: var(--now); line-height: 1.1; }
        .now .empty { font-size: clamp(24px, 4.8vmin, 48px); color: var(--muted); }
        ol { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.4vh; }
        .code { display: inline-block; font-size: .6em; color: var(--muted); margin-inline-end: .5em; white-space: nowrap; vertical-align: middle; }
        .now .name, li .who { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        li { display: flex; gap: 1.5vw; align-items: center; font-size: clamp(24px, 5.4vmin, 56px); font-weight: 700; padding: 1vh 1vw; border-radius: 1vw; background: rgba(255,255,255,.04); }
        li .n { width: 2.2em; text-align: center; color: var(--accent); }
        .count { margin-top: 2vh; color: var(--muted); font-size: clamp(18px, 3.2vmin, 30px); }
        .idle { grid-column: 1 / -1; display: grid; place-content: center; text-align: center; gap: 2vh; }
        .idle .big { font-size: clamp(36px, 9.6vmin, 96px); font-weight: 800; }
        .idle .small { color: var(--muted); font-size: clamp(20px, 3.8vmin, 40px); }
        @media (max-aspect-ratio: 1/1) { main { grid-template-columns: 1fr; grid-template-rows: auto 1fr; } }
    </style>
</head>
<body>
    <div id="board" style="display:contents">
        @include('tv.board')
    </div>

    <script>
        // Fetch the board again every few seconds. A failure leaves the last
        // one up and tries again later; nothing here can leave the TV on an
        // error page. A full reload once in a while, only after a good fetch,
        // clears whatever a browser left open for hours accumulates.
        (function () {
            var every = {{ max(5, $refreshSeconds) }} * 1000;
            var loadedAt = Date.now();
            var url = location.pathname + '?board=1';

            function tick() {
                fetch(url, { cache: 'no-store', credentials: 'omit' })
                    .then(function (r) { if (!r.ok) { throw r.status; } return r.text(); })
                    .then(function (html) {
                        if (Date.now() - loadedAt > 6 * 3600 * 1000) { location.reload(); return; }
                        document.getElementById('board').innerHTML = html;
                    })
                    .catch(function () {})
                    .finally(function () { setTimeout(tick, every); });
            }

            setTimeout(tick, every);
        })();
    </script>
</body>
</html>
