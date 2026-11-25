{{-- The board itself, fetched on its own by the page around it (tv.show). --}}
@php
    // Code and first name only. A first name that is one word of two (عبد
    // الرحمن, أبو بكر) keeps both, or the waiting room reads "عبد".
    $label = function ($booking) {
        $words = preg_split('/\s+/u', trim((string) $booking->patient?->name)) ?: [];
        $first = in_array($words[0] ?? '', ['عبد', 'ابو', 'أبو', 'ام', 'أم'], true)
            ? implode(' ', array_slice($words, 0, 2))
            : ($words[0] ?? '');

        return ['code' => $booking->patient?->code, 'name' => $first];
    };
@endphp
    <header>
        <div>
            <h1>🏥 {{ $branch->name }}</h1>
            <div class="sub">{{ $clinic->doctor?->name ?? $clinic->name }}</div>
        </div>
        <div class="clock">{{ \App\Support\Wire::time($now)['display'] }}</div>
    </header>

    <main>
        @if ($idle)
            <div class="idle">
                <div class="big">{{ __('displays.idle_title') }}</div>
                @if ($days = $branch->workingDaysLabel())
                    <div class="small">{{ __('displays.idle_days', ['days' => $days]) }}</div>
                @endif
            </div>
        @else
            <section class="panel now">
                <h2>{{ __('displays.with_doctor') }}</h2>
                @forelse ($with_doctor as $booking)
                    @php $who = $label($booking); @endphp
                    <div class="name">@if ($who['code'])<span class="code">{{ $who['code'] }}</span>@endif{{ $who['name'] }}</div>
                @empty
                    <div class="empty">{{ __('displays.nobody_in') }}</div>
                @endforelse
            </section>

            <section class="panel">
                <h2>{{ __('displays.next') }}</h2>
                @if ($next->isEmpty())
                    <div class="now"><div class="empty">{{ __('displays.nobody_waiting') }}</div></div>
                @else
                    <ol>
                        @foreach ($next as $i => $booking)
                            @php $who = $label($booking); @endphp
                            <li><span class="n">{{ $i + 1 }}</span>@if ($who['code'])<span class="code">{{ $who['code'] }}</span>@endif<span class="who">{{ $who['name'] }}</span></li>
                        @endforeach
                    </ol>
                    @if ($waiting > $next->count())
                        <div class="count">{{ __('displays.more_waiting', ['count' => $waiting - $next->count()]) }}</div>
                    @endif
                @endif
            </section>
        @endif
    </main>
