{{-- The sidebar that appears on the overview and services tabs. --}}
<aside class="side stack">
    <section class="card booking">
        <h2>{{ $heading }}</h2>
        <p class="lede">{{ $lead }}</p>

        <div class="steps">
            @foreach ([1, 2, 3] as $number)
                <div class="step">
                    <span class="n">{{ $number }}</span>
                    <span>
                        <b>{{ __('landing.book_step_'.$number) }}</b>
                        <small>{{ __('landing.book_step_'.$number.'_note') }}</small>
                    </span>
                </div>
            @endforeach
        </div>

        @if ($waLink)
            <a class="btn btn-wa" href="{{ $waLink }}">{{ __('landing.book_on_whatsapp') }}</a>
        @endif

        @if ($phone)
            <a class="btn btn-ghost" href="tel:{{ $phone }}">{{ __('landing.call') }}</a>
        @endif

        <p class="note">
            <span class="tick">@include('landing.partials.icon', ['name' => 'check', 'size' => 12])</span>
            {{ __('landing.no_online_payment') }}
        </p>
    </section>
</aside>
