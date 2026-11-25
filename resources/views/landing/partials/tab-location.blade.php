<section class="cols panel" id="panel-location" role="tabpanel" aria-labelledby="tab-location" hidden>
    <div class="stack">
        @if ($branches->count() > 1)
            {{-- One card per branch: where, which days, and a link that opens
                 the booking page on that branch (ELA-18). --}}
            @foreach ($branches as $branch)
                <section class="card">
                    <h2>🏥 {{ $branch->name }}</h2>
                    @if ($branch->displayAddress())
                        <p class="lede">{{ $branch->displayAddress() }}</p>
                    @endif
                    @if ($days = $branch->workingDaysLabel())
                        <p class="lede">{{ $days }}</p>
                    @endif
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:12px">
                        @if ($map = $branch->mapLink())
                            <a class="btn btn-ghost" href="{{ $map }}" target="_blank" rel="noopener">
                                @include('landing.partials.icon', ['name' => 'pin'])
                                {{ __('landing.directions') }}
                            </a>
                        @endif
                        @if ($selfBookingEnabled)
                            <a class="btn btn-book" href="{{ route('patient.book', $clinic->slug) }}?b={{ $branch->id }}">{{ __('landing.book_at_branch') }}</a>
                        @endif
                    </div>
                </section>
            @endforeach
        @else
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
        @endif
    </div>

    @include('landing.partials.booking-card', [
        'heading' => __('landing.book_title'),
        'lead' => __('landing.book_lead'),
    ])
</section>
