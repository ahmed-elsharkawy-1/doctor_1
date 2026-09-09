<section class="cols panel" id="panel-contact" role="tabpanel" aria-labelledby="tab-contact" hidden>
    <div class="stack">
        <section class="card">
            <h2>{{ __('landing.contact_title') }}</h2>

            <div class="rows">
                @if ($waLink)
                    <a class="row" href="{{ $waLink }}">
                        <span class="ico">@include('landing.partials.icon', ['name' => 'phone'])</span>
                        <span class="grow">
                            <b>{{ __('landing.contact_whatsapp') }}</b>
                            <small dir="ltr" style="display:block;text-align:start">{{ $phone?->national() }}</small>
                        </span>
                        <span class="badge">{{ __('landing.book_on_whatsapp') }}</span>
                    </a>
                @endif

                @if ($phone)
                    <a class="row" href="tel:{{ $phone }}">
                        <span class="ico">@include('landing.partials.icon', ['name' => 'phone'])</span>
                        <span class="grow">
                            <b>{{ __('landing.contact_phone') }}</b>
                            <small dir="ltr" style="display:block;text-align:start">{{ $phone->national() }}</small>
                        </span>
                        <span class="badge">{{ __('landing.call') }}</span>
                    </a>
                @endif

                @if ($clinic->address)
                    <div class="row">
                        <span class="ico">@include('landing.partials.icon', ['name' => 'pin'])</span>
                        <span class="grow">
                            <b>{{ __('landing.address') }}</b>
                            <small>{{ $clinic->address }}</small>
                        </span>
                    </div>
                @endif
            </div>

            <p class="hint">
                @include('landing.partials.icon', ['name' => 'info'])
                {{ __('landing.contact_note') }}
            </p>
        </section>
    </div>

    @include('landing.partials.booking-card', [
        'heading' => __('landing.book_title'),
        'lead' => __('landing.book_lead'),
    ])
</section>
