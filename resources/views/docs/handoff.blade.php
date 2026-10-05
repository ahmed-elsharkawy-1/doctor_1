@php
    use App\Support\TestClinic;

    $admin = fn (string $base) => $base.'/'.$panelPath;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Elayadah — Testing handoff</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #1f2933;
            --muted: #62717f;
            --line: #dde4ea;
            --panel: #ffffff;
            --soft: #f4f7f9;
            --brand: #185FA5;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #eef2f6;
            color: var(--ink);
            font: 15px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        main { max-width: 760px; margin: 0 auto; padding: 32px 16px 48px; }
        h1 { margin: 0 0 4px; font-size: 1.6rem; }
        .lead { margin: 0 0 24px; color: var(--muted); }
        section { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
        h2 { margin: 0 0 4px; font-size: 1.05rem; }
        .note { margin: 0 0 12px; color: var(--muted); font-size: .9rem; }
        dl { display: grid; grid-template-columns: 9.5rem 1fr; gap: 8px 12px; margin: 0; }
        dt { color: var(--muted); font-size: .9rem; }
        dd { margin: 0; overflow-wrap: anywhere; }
        code { background: var(--soft); border: 1px solid var(--line); border-radius: 6px; padding: 1px 6px; font-size: .88rem; }
        a { color: var(--brand); font-weight: 600; text-decoration: none; }
        a:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        th, td { text-align: start; padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; overflow-wrap: anywhere; }
        th { color: var(--muted); font-weight: 600; width: 9.5rem; }
        .rtl { direction: rtl; unicode-bidi: isolate; }
        pre { margin: 12px 0 0; padding: 12px 14px; background: #1f2933; color: #f4f7f9; border-radius: 10px; overflow-x: auto; font-size: .85rem; white-space: pre-wrap; }

        @media (max-width: 560px) {
            dl { grid-template-columns: 1fr; gap: 2px; }
            dd { margin-bottom: 8px; }
            th { width: 6.5rem; }
        }
    </style>
</head>
<body>
<main>
    <h1>Elayadah — Testing handoff</h1>
    <p class="lead">Links and logins for testing the system. In use today: the mobile app (through the API) and the admin panel.</p>

    <section>
        <h2>Test clinic — use this for all testing</h2>
        <p class="note">Not a real doctor. The same clinic and logins on staging and production.</p>
        <dl>
            <dt>Clinic</dt>
            <dd><span class="rtl">{{ TestClinic::NAME }}</span></dd>
            <dt>Doctor</dt>
            <dd><code>{{ TestClinic::DOCTOR_EMAIL }}</code> / <code>{{ TestClinic::DOCTOR_PASSWORD }}</code> — the app, and the doctor's reports</dd>
            <dt>Assistant</dt>
            <dd><code>{{ TestClinic::ASSISTANT_EMAIL }}</code> / <code>{{ TestClinic::ASSISTANT_PASSWORD }}</code> — the app</dd>
        </dl>
    </section>

    <section>
        <h2>Environments</h2>
        <p class="note">Test on staging first. Staging sends nothing (no SMS, no WhatsApp) and its verification code is always <code>1234</code>. Production sends real SMS codes.</p>
        <table>
            <tr><th></th><th>Staging</th><th>Production</th></tr>
            <tr>
                <th>API base URL</th>
                <td><code>{{ $stagingUrl }}/api/v1</code></td>
                <td><code>{{ $productionUrl }}/api/v1</code></td>
            </tr>
            <tr>
                <th>Doctor's page</th>
                <td><a href="{{ $stagingUrl }}/{{ TestClinic::SLUG }}">{{ $stagingUrl }}/{{ TestClinic::SLUG }}</a></td>
                <td><a href="{{ $productionUrl }}/{{ TestClinic::SLUG }}">{{ $productionUrl }}/{{ TestClinic::SLUG }}</a></td>
            </tr>
            <tr>
                <th>Doctor's reports</th>
                <td><a href="{{ $stagingUrl }}/reports">{{ $stagingUrl }}/reports</a></td>
                <td><a href="{{ $productionUrl }}/reports">{{ $productionUrl }}/reports</a></td>
            </tr>
            <tr>
                <th>Admin panel</th>
                <td><a href="{{ $admin($stagingUrl) }}">{{ $admin($stagingUrl) }}</a></td>
                <td><a href="{{ $admin($productionUrl) }}">{{ $admin($productionUrl) }}</a></td>
            </tr>
        </table>
        <p class="note" style="margin: 12px 0 0">Admin panel logins are shared privately by the team.</p>
    </section>

    <section>
        <h2>Mobile API</h2>
        <dl>
            <dt>Reference</dt>
            <dd><a href="{{ $apiDocsUrl }}">{{ $apiDocsUrl }}</a></dd>
            <dt>OpenAPI JSON</dt>
            <dd><a href="{{ $openApiUrl }}">{{ $openApiUrl }}</a></dd>
            <dt>Design map</dt>
            <dd><a href="{{ $designMapUrl }}">{{ $designMapUrl }}</a></dd>
        </dl>
<pre>POST {{ $stagingUrl }}/api/v1/auth/login
Accept: application/json
Content-Type: application/json

{ "email": "{{ TestClinic::DOCTOR_EMAIL }}", "password": "{{ TestClinic::DOCTOR_PASSWORD }}", "device_name": "my-phone" }</pre>
    </section>

    @if ($pilotClinic)
        <section>
            <h2>Pilot clinic</h2>
            <p class="note">A real clinic trying the app. Do not create test bookings here — use the test clinic.</p>
            <dl>
                <dt>Clinic</dt>
                <dd><span class="rtl">{{ $pilotClinic->name }}</span></dd>
                <dt>Doctor's page</dt>
                <dd>
                    @if ($pilotLandingUrl)
                        <a href="{{ $pilotLandingUrl }}">{{ $pilotLandingUrl }}</a>
                    @else
                        —
                    @endif
                </dd>
                <dt>Login</dt>
                <dd><code>{{ $pilotEmail }}</code> — password shared privately by the team</dd>
                <dt>WhatsApp</dt>
                <dd>{{ $pilotClinic->sendsWhatsApp() ? 'WhatsApp on — patients receive messages' : 'WhatsApp off — nothing is sent to patients' }}</dd>
            </dl>
        </section>
    @endif
</main>
</body>
</html>
