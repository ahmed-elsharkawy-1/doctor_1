{{-- One tile in the four-stat row. Skipped entirely when there is no value. --}}
@if (filled($value))
    <div class="stat">
        <span class="ico">@include('landing.partials.icon', ['name' => $icon])</span>
        <span>
            <span class="k">{{ $label }}</span>
            <span class="v">{{ $value }}</span>
        </span>
    </div>
@endif
