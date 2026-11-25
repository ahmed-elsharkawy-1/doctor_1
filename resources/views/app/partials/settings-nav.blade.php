{{-- Sub-navigation shared by the four settings screens. --}}
@php
    // Each tab with the ability its screen needs, so nobody is shown a tab
    // that would refuse them.
    $tabs = [
        'app.settings'             => [__('app.settings.general'), 'settings.manage'],
        'app.settings.hours'       => [__('app.settings.hours'), 'schedule.manage'],
        'app.settings.visit-types' => [__('app.settings.visit_types'), 'settings.manage'],
        'app.settings.holidays'    => [__('app.settings.holidays'), 'schedule.manage'],
        'app.settings.branches'    => [__('app.settings.branches'), 'branches.manage'],
    ];
    $user = auth()->user();

    // routeIs() can't tell on a Livewire update, which posts to Livewire's
    // own route; the page's path can.
    $here = trim(app('livewire')->isLivewireRequest() ? app('livewire')->originalPath() : request()->path(), '/');
@endphp

<div class="subnav">
    @foreach ($tabs as $route => [$label, $ability])
        @continue (! $user?->hasAbility($ability))
        <a href="{{ route($route) }}" @if ($here === trim(parse_url(route($route), PHP_URL_PATH), '/')) aria-current="page" @endif>
            {{ $label }}
        </a>
    @endforeach
</div>
