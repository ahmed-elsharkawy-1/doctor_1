{{--
    The clinic app's navigation. Every entry is gated on the same ability the
    API gates its endpoints with, so a screen never appears to an account that
    would be refused when it got there.
--}}
@php
    $user = auth()->user();
    $clinic = $user->activeClinic();

    // ELA-17: the current branch is always visible once there are two to
    // choose from; with one, nothing about branches is shown at all.
    $myBranches = $clinic === null ? collect() : $user->accessibleBranches($clinic);
    $currentBranch = request()->attributes->get('branch')
        ?? $myBranches->firstWhere('id', (int) session(\App\Services\V1\Branches\ActiveBranch::SESSION_KEY))
        ?? $myBranches->first();

    // The call list is only worth a place in the bar while someone is on it —
    // the count is the mobile app's home-screen banner, in a nav entry.
    $awaitingRebooking = $clinic === null
        ? 0
        : app(\App\Services\V1\Queue\QueueService::class)->awaitingRebookingCount($clinic, $currentBranch);

    $links = [
        ['route' => 'app.queue',        'label' => __('app.nav.queue'),     'ability' => 'queue.manage'],
        ['route' => 'app.bookings.new', 'label' => __('app.nav.new'),       'ability' => 'bookings.manage'],
        ['route' => 'app.patients',     'label' => __('app.nav.patients'),  'ability' => 'patients.view'],
        ['route' => 'app.delay',        'label' => __('app.nav.delay'),     'ability' => 'queue.manage'],
        // Hidden, not removed: unused for now, until it is settled with the
        // product and mobile teams. The screen and its route still work.
        ['route' => 'app.postpone',     'label' => __('app.nav.postpone'),  'ability' => 'queue.manage', 'hidden' => true],
        ['route' => 'app.reports',      'label' => __('app.nav.reports'),   'ability' => 'reports.view'],
        ['route' => 'app.reviews',      'label' => __('app.nav.reviews'),   'ability' => 'patients.view'],
        ['route' => 'app.messages',     'label' => __('app.nav.messages'),  'ability' => 'bookings.manage'],
        ['route' => 'app.settings',     'label' => __('app.nav.settings'),  'ability' => 'settings.manage'],
    ];

    // Only for a clinic that keeps a waiting list, with today's and later
    // patients counted the way the call list's entry is.
    // The branch the header is on keeps a list (ELA-21).
    if ($currentBranch?->offersWaitingList()) {
        $waiting = app(\App\Services\V1\WaitingList\WaitingListService::class)->upcomingCount($clinic, $currentBranch);

        array_splice($links, 2, 0, [[
            'route' => 'app.waiting-list',
            'label' => __('waiting_list.app.nav').($waiting > 0 ? ' ('.$waiting.')' : ''),
            'ability' => 'bookings.manage',
        ]]);
    }

    if ($awaitingRebooking > 0) {
        array_splice($links, 5, 0, [[
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
            {{-- One branch of several: nothing to switch, but say which. --}}
            @if ($myBranches->count() === 1 && $clinic !== null && $clinic->branches()->count() > 1)
                <small class="branch-here">🏥 {{ $currentBranch?->name }}</small>
            @endif
        </div>

        @if ($myBranches->count() > 1 && $currentBranch !== null)
            <details class="branch-switch">
                <summary aria-label="{{ __('app.branches.switch') }}">
                    🏥 {{ $currentBranch->name }}
                </summary>
                <form method="POST" action="{{ route('app.branch.switch') }}" class="branch-menu">
                    @csrf
                    @foreach ($myBranches as $choice)
                        <button type="submit" name="branch" value="{{ $choice->id }}"
                                @if ($choice->id === $currentBranch->id) aria-current="true" @endif>
                            {{ $choice->name }}
                            @unless ($choice->is_active)
                                <small>({{ __('app.settings.branch_inactive') }})</small>
                            @endunless
                        </button>
                    @endforeach
                </form>
            </details>
        @endif

        <div class="nav">
            @foreach ($links as $link)
                @continue (($link['hidden'] ?? false) || ! $user->hasAbility($link['ability']))
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
