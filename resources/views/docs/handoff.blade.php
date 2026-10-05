<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Doctor 1 — Developer Handoff</title>

    <style>
        :root {
            color-scheme: light;
            --ink: #1f2933;
            --muted: #62717f;
            --line: #d8e0e5;
            --panel: #ffffff;
            --soft: #f5f8fa;
            --brand: #0e6976;
            --brand-dark: #0a4d58;
            --warn: #8a5b00;
            --warn-bg: #fff7df;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #eef3f5;
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.55;
        }

        main {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
            padding: 48px 0;
        }

        header {
            display: grid;
            gap: 14px;
            margin-bottom: 28px;
        }

        h1, h2, h3, p { margin: 0; }

        h1 {
            font-size: clamp(2rem, 4vw, 3.4rem);
            line-height: 1.05;
            letter-spacing: 0;
        }

        h2 {
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--brand-dark);
        }

        h3 {
            font-size: 1rem;
            margin-bottom: 12px;
        }

        a {
            color: var(--brand-dark);
            font-weight: 700;
            text-decoration: none;
        }

        a:hover { text-decoration: underline; }

        .subhead {
            max-width: 760px;
            color: var(--muted);
            font-size: 1.05rem;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 22px;
            box-shadow: 0 10px 30px rgba(31, 41, 51, .07);
        }

        .wide { grid-column: 1 / -1; }

        .rows {
            display: grid;
            gap: 10px;
        }

        .row {
            display: grid;
            grid-template-columns: 150px minmax(0, 1fr);
            gap: 14px;
            align-items: start;
            padding: 10px 0;
            border-top: 1px solid var(--line);
        }

        .row:first-child {
            border-top: 0;
            padding-top: 0;
        }

        .label {
            color: var(--muted);
            font-size: .92rem;
        }

        code {
            display: inline-block;
            max-width: 100%;
            overflow-wrap: anywhere;
            border-radius: 6px;
            background: var(--soft);
            border: 1px solid var(--line);
            padding: 3px 7px;
            color: #17212b;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .92rem;
        }

        pre {
            margin: 0;
            overflow-x: auto;
            border-radius: 8px;
            background: #17212b;
            color: #e6edf3;
            padding: 16px;
            font-size: .9rem;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            border-radius: 999px;
            background: #dff3f5;
            color: var(--brand-dark);
            padding: 3px 10px;
            font-weight: 700;
            font-size: .85rem;
        }

        .notice {
            background: var(--warn-bg);
            color: var(--warn);
            border-color: #efd38d;
        }

        .links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            min-height: 40px;
            border-radius: 8px;
            padding: 8px 12px;
            background: var(--brand);
            color: #fff;
            font-weight: 800;
        }

        .button.secondary {
            background: #fff;
            color: var(--brand-dark);
            border: 1px solid var(--line);
        }

        .button:hover {
            text-decoration: none;
            background: var(--brand-dark);
        }

        .button.secondary:hover {
            background: var(--soft);
        }

        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--line);
        }

        .tab {
            appearance: none;
            border: 0;
            background: none;
            font: inherit;
            font-weight: 800;
            color: var(--muted);
            padding: 10px 4px;
            margin-bottom: -1px;
            border-bottom: 3px solid transparent;
            cursor: pointer;
        }

        .tab[aria-selected="true"] {
            color: var(--brand-dark);
            border-bottom-color: var(--brand);
        }

        .panel[hidden] { display: none; }

        /* The reservation flow, as numbered steps. */
        .flow {
            display: grid;
            gap: 0;
            counter-reset: step;
        }

        .step {
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr);
            gap: 14px;
            padding: 14px 0;
            border-top: 1px solid var(--line);
        }

        .step:first-child { border-top: 0; padding-top: 0; }

        .step::before {
            counter-increment: step;
            content: counter(step);
            display: grid;
            place-items: center;
            width: 28px;
            height: 28px;
            border-radius: 999px;
            background: var(--brand);
            color: #fff;
            font-weight: 800;
            font-size: .85rem;
        }

        .step .who {
            font-size: .78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
        }

        .step p { margin-top: 2px; }

        .queue-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .93rem;
        }

        .queue-table th {
            text-align: left;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
            padding: 0 10px 8px 0;
        }

        .queue-table td {
            padding: 9px 10px 9px 0;
            border-top: 1px solid var(--line);
            vertical-align: middle;
        }

        .rtl { direction: rtl; text-align: right; }

        @media (max-width: 760px) {
            main { width: min(100% - 24px, 1120px); padding: 28px 0; }
            .grid { grid-template-columns: 1fr; }
            .row { grid-template-columns: 1fr; gap: 4px; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <span class="pill">Test Environment</span>
        <h1>Doctor 1 Developer Handoff</h1>
        <p class="subhead">
            Shared test access for the web app, the admin dashboard and the demo clinic
            API account. For development and integration testing only.
        </p>
    </header>

    <div class="tabs" role="tablist">
        <button class="tab" role="tab" id="tab-web" aria-controls="panel-web" aria-selected="true">
            Web &amp; Reservation Flow
        </button>
        <button class="tab" role="tab" id="tab-api" aria-controls="panel-api" aria-selected="false">
            Mobile API
        </button>
    </div>

    <section class="grid panel" id="panel-api" role="tabpanel" aria-labelledby="tab-api" hidden>
        <article class="card">
            <h2>Dashboard</h2>
            <div class="rows">
                <div class="row">
                    <div class="label">URL</div>
                    <div><a href="{{ $adminUrl }}">{{ $adminUrl }}</a></div>
                </div>
                <div class="row">
                    <div class="label">Login</div>
                    {{-- This page is public. The admin panel controls every clinic,
                         so its login is never printed here. --}}
                    <div>Super-admin access is shared privately by the team.</div>
                </div>
            </div>
        </article>

        <article class="card">
            <h2>Clinic API Account</h2>
            <div class="rows">
                <div class="row">
                    <div class="label">Clinic</div>
                    <div><code class="rtl">{{ $clinic?->name ?? '—' }}</code> — a mock clinic for testing</div>
                </div>
                <div class="row">
                    <div class="label">Email</div>
                    <div><code>doctor@doctor1.test</code></div>
                </div>
                <div class="row">
                    <div class="label">Password</div>
                    <div><code>{{ \App\Support\TestClinic::DOCTOR_PASSWORD }}</code></div>
                </div>
                <div class="row">
                    <div class="label">Same on</div>
                    <div>local, staging and production</div>
                </div>
            </div>
        </article>

        <article class="card wide">
            <h2>API Links</h2>
            <div class="rows">
                <div class="row">
                    <div class="label">Base URL</div>
                    <div><code>{{ $apiBaseUrl }}</code></div>
                </div>
                <div class="row">
                    <div class="label">API Reference</div>
                    <div><a href="{{ $apiDocsUrl }}">{{ $apiDocsUrl }}</a></div>
                </div>
                <div class="row">
                    <div class="label">Design Map</div>
                    <div><a href="{{ $designMapUrl }}">{{ $designMapUrl }}</a></div>
                </div>
                <div class="row">
                    <div class="label">OpenAPI JSON</div>
                    <div><a href="{{ $openApiUrl }}">{{ $openApiUrl }}</a></div>
                </div>
            </div>
        </article>

        <article class="card wide">
            <h2>Login Request</h2>
            <pre><code>POST {{ $apiBaseUrl }}/auth/login
Accept: application/json
Content-Type: application/json

{
  "email": "{{ $demoEmail }}",
  "password": "{{ \App\Support\TestClinic::DOCTOR_PASSWORD }}",
  "device_name": "mobile-team"
}</code></pre>
        </article>

        <article class="card wide">
            <h2>Staging — test here first</h2>
            <p class="subhead">
                A full copy of the app with demo data only. Nothing is ever sent from it:
                no SMS, no WhatsApp. Point a staging build of the app here.
            </p>
            <div class="rows">
                <div class="row">
                    <div class="label">Website</div>
                    <div><a href="{{ $stagingUrl }}">{{ $stagingUrl }}</a></div>
                </div>
                <div class="row">
                    <div class="label">API base URL</div>
                    <div><code>{{ $stagingUrl }}/api/v1</code></div>
                </div>
                <div class="row">
                    <div class="label">Logins</div>
                    <div><code>{{ $demoEmail }}</code> / <code>{{ \App\Support\TestClinic::DOCTOR_PASSWORD }}</code> · <code>{{ $demoAssistant }}</code> / <code>{{ \App\Support\TestClinic::ASSISTANT_PASSWORD }}</code></div>
                </div>
                <div class="row">
                    <div class="label">Verification code</div>
                    <div>Always <code>1234</code> on staging</div>
                </div>
            </div>
        </article>

        <article class="card wide">
            <h2>What's new in the API</h2>
            <div class="rows">
                <div class="row">
                    <div class="label"><code>/bootstrap</code></div>
                    <div><code>clinic.whatsapp_enabled</code> — whether this clinic sends WhatsApp at all. When <code>false</code>, hide message sending; "cancel the day" still cancels, without messages.</div>
                </div>
                <div class="row">
                    <div class="label">Error</div>
                    <div><code>WHATSAPP_DISABLED</code> (409) from <code>/broadcasts</code> and <code>/bookings/{booking}/message</code> for any template other than <code>day_cancelled</code> while WhatsApp is off.</div>
                </div>
                <div class="row">
                    <div class="label"><code>/message-templates</code></div>
                    <div>Lists only <code>day_cancelled</code> while the clinic's WhatsApp is off.</div>
                </div>
                <div class="row">
                    <div class="label">Required now?</div>
                    <div>No. Nothing was removed or renamed; the app keeps working without changes.</div>
                </div>
            </div>
        </article>

        <article class="card wide notice">
            <h3>Production Note</h3>
            <p>
                This page is controlled by <code>API_DOCS_ENABLED</code>. Disable it when the
                shared test access is no longer needed.
            </p>
        </article>

    </section>

    <section class="grid panel" id="panel-web" role="tabpanel" aria-labelledby="tab-web">
        @include('docs.partials.reservation-flow')
    </section>

    <section class="grid">
        <article class="wide links">
            <a class="button" href="{{ $apiDocsUrl }}">Open API Docs</a>
            <a class="button secondary" href="{{ $designMapUrl }}">Open Design Map</a>
            <a class="button secondary" href="{{ $adminUrl }}">Open Dashboard</a>
            <a class="button secondary" href="{{ $openApiUrl }}">Open OpenAPI JSON</a>
        </article>
    </section>
</main>

<script>
    // Two panels, no dependency. The chosen tab survives a refresh.
    const tabs = document.querySelectorAll('.tab');

    function show(id) {
        tabs.forEach(tab => {
            const selected = tab.id === id;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !selected;
        });
        try { localStorage.setItem('handoff-tab', id); } catch (e) { /* private mode */ }
    }

    tabs.forEach(tab => tab.addEventListener('click', () => show(tab.id)));

    try {
        const saved = localStorage.getItem('handoff-tab');
        if (saved && document.getElementById(saved)) { show(saved); }
    } catch (e) { /* private mode */ }
</script>
</body>
</html>
