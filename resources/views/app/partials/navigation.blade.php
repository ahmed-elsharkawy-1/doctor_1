{{--
    The clinic app's navigation. Every entry is gated on the same ability the
    API gates its endpoints with, so a screen never appears to an account that
    would be refused when it got there.
--}}
@php
    $links = [
        ['route' => 'app.queue',        'label' => __('app.nav.queue'),     'ability' => 'queue.manage'],
        ['route' => 'app.bookings.new', 'label' => __('app.nav.new'),       'ability' => 'bookings.manage'],
        ['route' => 'app.patients',     'label' => __('app.nav.patients'),  'ability' => 'patients.view'],
        ['route' => 'app.settings',     'label' => __('app.nav.settings'),  'ability' => 'settings.manage'],
    ];

    $user = auth()->user();
@endphp

<nav class="appbar">
    <div class="inner">
        <div class="who">
            <b>{{ $user->activeClinic()?->name }}</b>
            <small>{{ $user->name }}</small>
        </div>

        <div class="nav">
            @foreach ($links as $link)
                @continue (! $user->hasAbility($link['ability']))
                <a href="{{ route($link['route']) }}"
                   @if (request()->routeIs($link['route'])) aria-current="page" @endif>
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>

        <form method="POST" action="{{ route('app.logout') }}">
            @csrf
            <button type="submit" class="btn btn-sm">{{ __('app.queue.sign_out') }}</button>
        </form>
    </div>
</nav>
