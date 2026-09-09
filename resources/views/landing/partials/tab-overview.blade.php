<section class="cols panel" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview">
    <div class="stack">
        @if (filled($doctor?->bio))
            <section class="card">
                <h2>{{ __('landing.about') }}</h2>
                <p class="lede">{{ $doctor->bio }}</p>
            </section>
        @endif

        @if ($treatmentAreas->isNotEmpty())
            <section class="card">
                <h2>{{ __('landing.treatment_areas') }}</h2>
                <div class="rows">
                    @foreach ($treatmentAreas as $area)
                        <div class="row">
                            <span class="ico">@include('landing.partials.icon', ['name' => $area->icon ?: 'activity'])</span>
                            <span class="grow">
                                <b>{{ $area->title }}</b>
                                <small>{{ $area->description }}</small>
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif


        @if ($photos->isNotEmpty())
            <section class="card">
                <h2>{{ __('landing.photos') }}</h2>
                <div class="gallery">
                    @foreach ($photos as $photo)
                        @continue ($photo->url() === null)
                        <figure class="shot" style="margin:0">
                            <img src="{{ $photo->url() }}" alt="{{ $photo->caption ?: $clinic->name }}" loading="lazy">
                            @if ($photo->caption)
                                <figcaption>{{ $photo->caption }}</figcaption>
                            @endif
                        </figure>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    @include('landing.partials.booking-card', [
        'heading' => __('landing.book_title'),
        'lead' => __('landing.book_lead'),
    ])
</section>
