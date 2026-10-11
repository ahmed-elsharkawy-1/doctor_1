{{--
    A clinic's QR poster as one self-contained SVG: fonts and the logo are
    embedded, so the browser can turn it into a PNG with nothing to fetch.

    Every position comes from App\Services\V1\QrPoster\QrPoster. Text marked
    `data-fit` is shrunk by the page, after the font loads, if it measures
    wider than the value given.
--}}
@php
    /** @var \App\Services\V1\QrPoster\QrPoster $poster */
    $w = $poster->width;
    $h = $poster->height;
    $cx = $w / 2;
    $colors = $poster->colors;
    $qr = $poster->qr;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {{ $w }} {{ $h }}" width="{{ $w }}" height="{{ $h }}" data-qr-poster>
    <defs>
        <style>{!! $fonts !!} .poster-text{font-family:'Tajawal Poster',Tajawal,sans-serif;fill:{{ $colors['text'] }};}</style>
        <linearGradient id="poster-bg" x1="0" y1="0" x2="0.35" y2="1">
            <stop offset="0" stop-color="{{ $colors['background'] }}"/>
            <stop offset="1" stop-color="{{ $colors['background_deep'] }}"/>
        </linearGradient>
        <linearGradient id="poster-sheen" x1="0" y1="1" x2="1" y2="0">
            <stop offset="0" stop-color="{{ $colors['sheen'] }}" stop-opacity="0"/>
            <stop offset="0.5" stop-color="{{ $colors['sheen'] }}" stop-opacity="{{ $colors['sheen_opacity'] * 1.6 }}"/>
            <stop offset="1" stop-color="{{ $colors['sheen'] }}" stop-opacity="{{ $colors['sheen_opacity'] * 0.4 }}"/>
        </linearGradient>
        <radialGradient id="poster-glow" cx="0.5" cy="0.5" r="0.5">
            <stop offset="0" stop-color="{{ $colors['sheen'] }}" stop-opacity="{{ $colors['glow_opacity'] }}"/>
            <stop offset="1" stop-color="{{ $colors['sheen'] }}" stop-opacity="0"/>
        </radialGradient>
    </defs>

    <rect width="{{ $w }}" height="{{ $h }}" fill="url(#poster-bg)"/>
    {{-- A soft sheen across the diagonal, rising from the bottom left, and a
         thinner one above it. --}}
    <polygon points="0,{{ $h * 0.5 }} {{ $w }},{{ $h * 0.06 }} {{ $w }},{{ $h * 0.5 }} 0,{{ $h * 0.94 }}" fill="url(#poster-sheen)"/>
    <polygon points="0,{{ $h * 0.40 }} {{ $w }},{{ $h * -0.04 }} {{ $w }},{{ $h * 0.01 }} 0,{{ $h * 0.45 }}" fill="{{ $colors['sheen'] }}" fill-opacity="{{ $colors['sheen_opacity'] }}"/>
    {{-- Light behind the code, so the tile sits on the poster rather than on top of it. --}}
    <circle cx="{{ $cx }}" cy="{{ $qr['y'] + $qr['size'] / 2 }}" r="{{ $qr['size'] * 0.95 }}" fill="url(#poster-glow)"/>

    <text class="poster-text" x="{{ $cx }}" y="{{ $poster->nameY }}" font-size="{{ $poster->nameSize }}" font-weight="800"
          text-anchor="middle" direction="rtl" data-fit="{{ $poster->textWidth }}">{{ $poster->name }}</text>

    @foreach ($poster->titleLines as $line)
        <text class="poster-text" x="{{ $cx }}" y="{{ $line['y'] }}" font-size="{{ $poster->titleSize }}" font-weight="500"
              text-anchor="middle" direction="rtl" data-fit="{{ $poster->textWidth }}">{{ $line['text'] }}</text>
    @endforeach

    <rect x="{{ $qr['x'] }}" y="{{ $qr['y'] + $qr['size'] * 0.02 }}" width="{{ $qr['size'] }}" height="{{ $qr['size'] }}" rx="{{ $qr['radius'] }}" fill="#000000" fill-opacity="{{ $colors['shadow_opacity'] }}"/>
    <rect x="{{ $qr['x'] }}" y="{{ $qr['y'] }}" width="{{ $qr['size'] }}" height="{{ $qr['size'] }}" rx="{{ $qr['radius'] }}" fill="{{ $colors['tile'] }}"/>
    <g transform="translate({{ $qr['x'] + $qr['padding'] }} {{ $qr['y'] + $qr['padding'] }}) scale({{ $qr['moduleSize'] }})">
        <path d="{{ $qr['path'] }}" fill="{{ $colors['modules'] }}" shape-rendering="crispEdges"/>
    </g>

    @foreach ($poster->callToActionLines as $line)
        <text class="poster-text" x="{{ $cx }}" y="{{ $line['y'] }}" font-size="{{ $poster->callToActionSize }}" font-weight="800"
              text-anchor="middle" direction="rtl" data-fit="{{ $poster->textWidth }}">{{ $line['text'] }}</text>
    @endforeach

    @if ($poster->logo !== '')
        <image href="{{ $poster->logo }}" x="{{ ($w - $poster->logoWidth) / 2 }}" y="{{ $poster->logoY }}"
               width="{{ $poster->logoWidth }}" height="{{ $poster->logoHeight }}"/>
    @endif

    <text class="poster-text" x="{{ $cx }}" y="{{ $poster->sloganY }}" font-size="{{ $poster->sloganSize }}" font-weight="500"
          text-anchor="middle" direction="rtl" fill-opacity="0.9" data-fit="{{ $poster->textWidth }}">{{ $poster->slogan }}</text>
</svg>
