{{--
    One icon set, stroke 2, drawn here rather than stored anywhere. A
    treatment area only ever supplies a key, so nothing operator-entered
    reaches the page as markup.
--}}
@php
    $paths = [
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 11h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'phone' => '<path d="M22 16.9v2a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 3.2 2 2 0 0 1 4.1 1h2a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L7.1 8.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'stethoscope' => '<path d="M8 3v4a4 4 0 0 0 8 0V3"/><path d="M12 11v4a5 5 0 0 1-10 0"/><circle cx="19" cy="14" r="2"/>',
        'activity' => '<path d="M3 12h4l3 8 4-16 3 8h4"/>',
        'spine' => '<path d="M12 3v18"/><path d="M8 6h8M8 10h8M8 14h8M8 18h8"/>',
        'joint' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v6M12 15v6M3 12h6M15 12h6"/>',
        'heart' => '<path d="M12 20s-7-4.6-7-9.5A4 4 0 0 1 12 7a4 4 0 0 1 7 3.5C19 15.4 12 20 12 20Z"/>',
        'baby' => '<circle cx="12" cy="9" r="5"/><path d="M9 8h.01M15 8h.01M10 12a3 3 0 0 0 4 0"/><path d="M6 21a6 6 0 0 1 12 0"/>',
        'tooth' => '<path d="M7 3c3 0 3 1 5 1s2-1 5-1a3 3 0 0 1 3 3c0 4-2 4-2.5 9-.3 3-.7 6-2 6s-1.5-5-2.5-5-1.2 5-2.5 5-1.7-3-2-6C8.5 10 6.5 10 6.5 6A3 3 0 0 1 7 3Z"/>',
        'eye' => '<path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/>',
        'brain' => '<path d="M9 4a3 3 0 0 0-3 3 3 3 0 0 0-1 5.8V15a4 4 0 0 0 4 4h1V4Z"/><path d="M15 4a3 3 0 0 1 3 3 3 3 0 0 1 1 5.8V15a4 4 0 0 1-4 4h-1V4Z"/>',
        'bone' => '<path d="M7 10a2.5 2.5 0 1 1 1.8-4.3A2.5 2.5 0 1 1 11 8l5 5a2.5 2.5 0 1 1 2.3 3.7A2.5 2.5 0 1 1 14 17l-5-5Z"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="m4 18 5-4 4 3 3-2 4 3"/>',
    ];

    $size = $size ?? 16;
@endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">{!! $paths[$name] ?? $paths['info'] !!}</svg>
