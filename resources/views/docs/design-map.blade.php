<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>

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
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #eef3f5;
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.65;
        }

        main {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
            padding: 36px 0 56px;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        nav a {
            border-radius: 8px;
            background: var(--panel);
            border: 1px solid var(--line);
            color: var(--brand-dark);
            padding: 8px 12px;
            font-weight: 800;
            text-decoration: none;
        }

        article {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: clamp(18px, 4vw, 36px);
            box-shadow: 0 10px 30px rgba(31, 41, 51, .07);
        }

        h1, h2, h3 {
            line-height: 1.2;
            letter-spacing: 0;
        }

        h1 { margin-top: 0; font-size: clamp(2rem, 4vw, 3rem); }
        h2 { margin-top: 2.2rem; color: var(--brand-dark); }
        h3 { margin-top: 1.5rem; }

        a {
            color: var(--brand-dark);
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            overflow-wrap: anywhere;
        }

        th, td {
            border: 1px solid var(--line);
            padding: 10px 12px;
            vertical-align: top;
            text-align: left;
        }

        th { background: var(--soft); }

        code {
            border-radius: 6px;
            background: var(--soft);
            border: 1px solid var(--line);
            padding: 2px 6px;
            color: #17212b;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .92em;
        }

        pre {
            overflow-x: auto;
            border-radius: 8px;
            background: #17212b;
            color: #e6edf3;
            padding: 16px;
        }

        pre code {
            border: 0;
            background: transparent;
            color: inherit;
            padding: 0;
        }

        @media (max-width: 760px) {
            main { width: min(100% - 24px, 1120px); padding-top: 24px; }
            table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>
<main>
    <nav aria-label="Documentation links">
        <a href="{{ $apiDocsUrl }}">API Docs</a>
        <a href="{{ $handoffUrl }}">Handoff</a>
        <a href="{{ $openApiUrl }}">OpenAPI JSON</a>
    </nav>

    <article>
        {!! $html !!}
    </article>
</main>
</body>
</html>
