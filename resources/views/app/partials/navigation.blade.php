{{--
    The clinic app's navigation. Every entry is gated on the same ability the
    API gates its endpoints with, so a screen never appears to an account that
    would be refused when it got there.
--}}
@php
    $user = auth()->user();
    $clinic = $user->activeClinic();

    // The call list is only worth a place in the bar while someone is on it —
    // the count is the mobile app's home-screen banner, in a nav entry.
    $awaitingRebooking = $clinic === null
        ? 0
        : app(\App\Services\V1\Queue\QueueService::class)->awaitingRebookingCount($clinic);

    $links = [
        ['route' => 'app.queue',        'label' => __('app.nav.queue'),     'ability' => 'queue.manage'],
        ['route' => 'app.bookings.new', 'label' => __('app.nav.new'),       'ability' => 'bookings.manage'],
        ['route' => 'app.patients',     'label' => __('app.nav.patients'),  'ability' => 'patients.view'],
        ['route' => 'app.postpone',     'label' => __('app.nav.postpone'),  'ability' => 'queue.manage'],
        ['route' => 'app.reports',      'label' => __('app.nav.reports'),   'ability' => 'reports.view'],
        ['route' => 'app.messages',     'label' => __('app.nav.messages'),  'ability' => 'bookings.manage'],
        ['route' => 'app.settings',     'label' => __('app.nav.settings'),  'ability' => 'settings.manage'],
    ];

    if ($awaitingRebooking > 0) {
        array_splice($links, 4, 0, [[
            'route' => 'app.rebooking',
            'label' => __('app.nav.rebooking').' ('.$awaitingRebooking.')',
            'ability' => 'queue.manage',
        ]]);
    }
@endphp

<nav class="appbar">
    <div class="inner">
        <img class="brand-mark" src="{{ asset(config('clinic.brand.logo')) }}"
             alt="{{ config('clinic.brand.name') }}" width="34" height="34">

        <div class="who">
            <b>{{ $clinic?->name }}</b>
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
