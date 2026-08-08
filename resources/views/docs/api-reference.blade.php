<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — API reference</title>

    <style>
        body { margin: 0; padding: 0; font-family: system-ui, sans-serif; }

        /* Shown only if the renderer never loads — usually no internet. */
        #fallback {
            display: none;
            max-width: 40rem;
            margin: 4rem auto;
            padding: 0 1.5rem;
            color: #22303a;
            line-height: 1.7;
        }
        #fallback h1 { font-size: 1.25rem; }
        #fallback code {
            background: #f1f0ec;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.9em;
        }
        #fallback a { color: #0e5c6b; }
    </style>
</head>
<body>

<redoc spec-url="{{ $specUrl }}" hide-download-button="false"></redoc>

<div id="fallback">
    <h1>The renderer could not load</h1>
    <p>
        This page pulls Redoc from a CDN, so it needs an internet connection.
        The spec itself is local and always available:
    </p>
    <ul>
        <li><a href="{{ $specUrl }}">{{ $specUrl }}</a> — the spec as JSON</li>
        <li><code>docs/api/v1/openapi.yaml</code> — the source of truth in the repo</li>
        <li><code>docs/api/v1/doctor1.postman_collection.json</code> — import into Postman</li>
    </ul>
</div>

<script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"
        onerror="document.getElementById('fallback').style.display='block'"></script>

<script>
    // The script tag can load and still fail to define Redoc (blocked, cached
    // error page). Check for the element having actually rendered.
    setTimeout(function () {
        var el = document.querySelector('redoc');

        if (!el || el.children.length === 0) {
            document.getElementById('fallback').style.display = 'block';
        }
    }, 4000);
</script>

</body>
</html>
