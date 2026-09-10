{{-- Sub-navigation shared by the four settings screens. --}}
@php
    $tabs = [
        'app.settings'             => __('app.settings.general'),
        'app.settings.hours'       => __('app.settings.hours'),
        'app.settings.visit-types' => __('app.settings.visit_types'),
        'app.settings.holidays'    => __('app.settings.holidays'),
    ];
@endphp

<div class="subnav">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif>
            {{ $label }}
        </a>
    @endforeach
</div>
