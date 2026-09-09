<section class="cols panel" id="panel-location" role="tabpanel" aria-labelledby="tab-location" hidden>
    <div class="stack">
        <section class="card">
            <h2>{{ __('landing.address') }}</h2>

            @if ($clinic->address)
                <p class="lede">{{ $clinic->address }}</p>
            @endif

            @if ($mapQuery)
                {{-- Keyless embed: no API key to leak and nothing to bill. --}}
                <iframe class="map" style="margin-top:14px" loading="lazy" title="{{ __('landing.tab_location') }}"
                        referrerpolicy="no-referrer-when-downgrade"
                        src="https://maps.google.com/maps?q={{ urlencode($mapQuery) }}&output=embed&hl={{ app()->getLocale() }}"></iframe>

                <a class="btn btn-ghost" style="margin-top:12px" href="{{ $mapLink }}" target="_blank" rel="noopener">
                    @include('landing.partials.icon', ['name' => 'pin'])
                    {{ __('landing.directions') }}
                </a>
            @endif
        </section>
    </div>

    @include('landing.partials.booking-card', [
        'heading' => __('landing.book_title'),
        'lead' => __('landing.book_lead'),
    ])
</section>
