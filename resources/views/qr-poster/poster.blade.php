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
    </defs>

    <rect width="{{ $w }}" height="{{ $h }}" fill="{{ $colors['background'] }}"/>
    {{-- The lighter diagonal band, rising from the bottom left. --}}
    <polygon points="0,{{ $h * 0.5 }} {{ $w }},{{ $h * 0.06 }} {{ $w }},{{ $h * 0.5 }} 0,{{ $h * 0.94 }}" fill="{{ $colors['band'] }}"/>

    <text class="poster-text" x="{{ $cx }}" y="{{ $poster->nameY }}" font-size="{{ $poster->nameSize }}" font-weight="800"
          text-anchor="middle" direction="rtl" data-fit="{{ $poster->textWidth }}">{{ $poster->name }}</text>

    @foreach ($poster->titleLines as $line)
        <text class="poster-text" x="{{ $cx }}" y="{{ $line['y'] }}" font-size="{{ $poster->titleSize }}" font-weight="500"
              text-anchor="middle" direction="rtl" data-fit="{{ $poster->textWidth }}">{{ $line['text'] }}</text>
    @endforeach

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

    <text class="poster-text" x="{{ $cx }}" y="{{ $poster->domainY }}" font-size="{{ $poster->domainSize }}" font-weight="500"
          text-anchor="middle" direction="ltr" data-fit="{{ $poster->textWidth }}">{{ $poster->domain }}</text>
</svg>
