{{--
    What /handoff shows until the team's access code is entered. Deliberately
    carries nothing of the page behind it: the check is on the server, so the
    logins are not in this page's source at all.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Elayadah — Testing handoff</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #eef2f6; color: #1f2933;
               font: 15px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; padding: 16px; }
        form { width: 100%; max-width: 360px; background: #fff; border: 1px solid #dde4ea; border-radius: 14px; padding: 24px; }
        h1 { margin: 0 0 4px; font-size: 1.2rem; }
        p { margin: 0 0 16px; color: #62717f; font-size: .9rem; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input { width: 100%; padding: 11px 12px; border: 1px solid #dde4ea; border-radius: 10px; font: inherit; margin-bottom: 12px; }
        button { width: 100%; padding: 11px; border: 0; border-radius: 10px; background: #185FA5; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .error { color: #c0392b; font-size: .9rem; margin: -4px 0 12px; }
    </style>
</head>
<body>
    <form method="POST" action="{{ route('handoff.unlock') }}">
        @csrf
        <h1>Elayadah — Testing handoff</h1>
        <p>For the team. Enter the access code to continue.</p>
        <label for="code">Access code</label>
        <input id="code" name="code" type="password" autocomplete="current-password" required autofocus>
        @error('code')<div class="error" role="alert">{{ $message }}</div>@enderror
        <button type="submit">Open</button>
    </form>
</body>
</html>
