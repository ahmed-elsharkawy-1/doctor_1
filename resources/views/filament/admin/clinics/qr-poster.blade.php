@php
    /** @var \App\Services\V1\QrPoster\QrPoster $poster */
@endphp
<x-filament-panels::page>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: 1.5rem; align-items: start;">
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <form wire:submit="save" style="display: flex; flex-direction: column; gap: 1rem;">
                {{ $this->form }}

                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <x-filament::button type="submit" icon="heroicon-o-check">
                        {{ __('filament.qr_poster.save') }}
                    </x-filament::button>
                    <x-filament::button type="button" color="gray" icon="heroicon-o-arrow-uturn-right"
                                        wire:click="resetToDefaults"
                                        wire:confirm="{{ __('filament.qr_poster.reset_confirm') }}">
                        {{ __('filament.qr_poster.reset') }}
                    </x-filament::button>
                </div>
            </form>

            <x-filament::section :heading="__('filament.qr_poster.stats.heading')"
                                 :description="__('filament.qr_poster.stats.hint')">
                <dl style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin: 0;">
                    @foreach ([
                        'scans_30_days' => $stats['scans_30_days'],
                        'bookings_30_days' => $stats['bookings_30_days'],
                        'scans' => $stats['scans'],
                        'bookings' => $stats['bookings'],
                    ] as $key => $value)
                        <div>
                            <dt style="font-size: 0.8rem; opacity: 0.7;">{{ __('filament.qr_poster.stats.'.$key) }}</dt>
                            <dd style="font-size: 1.6rem; font-weight: 700; margin: 0;" data-stat="{{ $key }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('filament.qr_poster.preview')">
            <div
                {{-- Re-created whenever the poster changes, so the text is
                     measured again against what is actually drawn. --}}
                wire:key="poster-{{ md5(serialize([$poster->name, $poster->nameSize, $poster->titleLines, $poster->titleSize, $poster->qr['size'], $poster->callToActionLines, $poster->url])) }}"
                x-data="qrPoster(@js($poster->fileName))"
                style="display: flex; flex-direction: column; gap: 1rem;"
            >
                <div x-ref="poster" style="max-width: 460px; width: 100%; margin: 0 auto; box-shadow: 0 4px 18px rgba(0,0,0,.18); border-radius: 6px; overflow: hidden; line-height: 0;">
                    <div style="width: 100%;" class="qr-poster-frame">
                        @include('qr-poster.poster', ['poster' => $poster, 'fonts' => $fonts])
                    </div>
                </div>

                <x-filament::button type="button" icon="heroicon-o-arrow-down-tray" x-on:click="download()" x-bind:disabled="busy" style="align-self: center;">
                    {{ __('filament.qr_poster.download') }}
                </x-filament::button>

                <p style="font-size: 0.8rem; opacity: 0.75; text-align: center; margin: 0;">
                    {{ __('filament.qr_poster.link') }}
                    <bdi><a href="{{ $poster->url }}" target="_blank" rel="noopener" style="text-decoration: underline;">{{ $poster->url }}</a></bdi>
                </p>
            </div>
        </x-filament::section>
    </div>

    <style>
        .qr-poster-frame svg { width: 100%; height: auto; display: block; }
    </style>

    {{-- A plain global rather than @script: it must exist before Alpine reads
         the x-data above, and a key change re-creates that element. --}}
    <script>
        window.qrPoster = (fileName) => ({
            busy: false,

            async init() {
                await this.fontsReady();
                this.fit();
            },

            svg() {
                return this.$refs.poster.querySelector('svg');
            },

            fontsReady() {
                if (! document.fonts) {
                    return Promise.resolve();
                }

                return Promise.all([
                    document.fonts.load("800 100px 'Tajawal Poster'", 'ابج'),
                    document.fonts.load("500 100px 'Tajawal Poster'", 'ابج'),
                ]).catch(() => null);
            },

            // Shrinks any line still wider than the poster allows. The server
            // only estimates widths; this measures them.
            fit() {
                this.svg().querySelectorAll('[data-fit]').forEach((text) => {
                    const max = Number(text.dataset.fit);
                    let size = Number(text.getAttribute('font-size'));
                    const floor = size * 0.4;

                    while (text.getComputedTextLength() > max && size > floor) {
                        size *= 0.97;
                        text.setAttribute('font-size', size.toFixed(1));
                    }
                });
            },

            async download() {
                this.busy = true;

                try {
                    await this.fontsReady();
                    this.fit();

                    const svg = this.svg();
                    const width = Number(svg.getAttribute('width'));
                    const height = Number(svg.getAttribute('height'));
                    const markup = new XMLSerializer().serializeToString(svg);
                    const url = URL.createObjectURL(new Blob([markup], { type: 'image/svg+xml;charset=utf-8' }));

                    const image = new Image();
                    image.src = url;
                    await image.decode();
                    // Fonts embedded in an SVG image can settle a moment after
                    // it decodes; drawing too early falls back to a system font.
                    await new Promise((resolve) => setTimeout(resolve, 400));

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    canvas.getContext('2d').drawImage(image, 0, 0, width, height);
                    URL.revokeObjectURL(url);

                    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = fileName;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
                } finally {
                    this.busy = false;
                }
            },
        });
    </script>
</x-filament-panels::page>
