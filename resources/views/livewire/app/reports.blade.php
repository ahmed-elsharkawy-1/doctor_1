@php
    // The payload is the mobile app's, so every number arrives already
    // formatted and already localised. Nothing is computed in this file.
    $arrow = fn (string $direction) => match ($direction) {
        'up' => '▲',
        'down' => '▼',
        default => '–',
    };

    $trendClass = fn (string $direction) => match ($direction) {
        'up' => 'up',
        'down' => 'down',
        default => 'flat',
    };
@endphp

<div class="wrap">
    <style>
        .rep { padding: 1rem; margin-bottom: 0.6rem; }
        .rep h2 { font-size: 0.9rem; color: var(--muted); margin: 0 0 0.35rem; font-weight: 600; }
        .rep .big { font-size: 1.5rem; font-weight: 700; line-height: 1.3; }
        .rep .sub { color: var(--muted); font-size: 0.85rem; }

        .trend { font-size: 0.85rem; font-weight: 600; margin-top: 0.35rem; }
        .trend.up { color: var(--brand); }
        .trend.down { color: var(--danger); }
        .trend.flat { color: var(--muted); }

        /* The month, day by day. A bar per day, scaled to the busiest one —
           enough to read the shape of a month at a glance. */
        .spark {
            display: flex;
            align-items: flex-end;
            gap: 2px;
            height: 5rem;
            margin-top: 0.6rem;
        }

        .spark span {
            flex: 1;
            min-height: 2px;
            background: var(--brand-soft);
            border-radius: 2px 2px 0 0;
        }

        .spark span.has { background: var(--brand); }

        .facts { display: grid; gap: 0.6rem; grid-template-columns: 1fr 1fr; }
        .facts .card { padding: 0.85rem; }
        .facts .n { font-size: 1.25rem; font-weight: 700; }
        .facts .k { color: var(--muted); font-size: 0.8rem; }

        @media (min-width: 48rem) {
            .money { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; }
            .money .rep { margin-bottom: 0; }
            .facts { grid-template-columns: repeat(4, 1fr); }
        }
    </style>

    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 12px">{{ __('app.reports.title') }}</h1>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @if ($revenue !== null)
        <div class="money">
            @foreach (['today', 'this_week', 'this_month'] as $key)
                @php $p = $revenue['periods'][$key]; @endphp
                <div class="card rep" wire:key="revenue-{{ $key }}">
                    <h2>{{ __('reports.period.'.$key) }}</h2>
                    <div class="big">{{ $p['total']['display'] }}</div>
                    <div class="sub">
                        {{ __('app.reports.visits', ['count' => $p['completed_visits']]) }}
                    </div>
                    <div class="trend {{ $trendClass($p['comparison']['direction']) }}">
                        {{ $arrow($p['comparison']['direction']) }}
                        {{ $p['comparison']['difference']['display'] }}
                        <span class="sub">{{ $p['comparison']['label'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        @php
            $daily = $revenue['daily'];
            $peak = max(1, max(array_map(fn ($d) => (float) $d['total']['value'], $daily ?: [['total' => ['value' => 0]]])));
        @endphp

        <div class="card rep" style="margin-top:0.6rem">
            <h2>{{ __('app.reports.daily') }}</h2>
            <div class="spark">
                @foreach ($daily as $day)
                    @php $value = (float) $day['total']['value']; @endphp
                    <span class="@if ($value > 0) has @endif"
                          style="height:{{ max(2, (int) round($value / $peak * 100)) }}%"
                          title="{{ $day['date']['display'] }} — {{ $day['total']['display'] }}"></span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Retention --}}
    <div class="card rep" style="margin-top:0.6rem">
        <h2>{{ __('app.reports.retention') }}</h2>

        <div class="chips" style="margin-bottom:0.85rem">
            @foreach ($periods as $value => $label)
                <button type="button" class="chip"
                        aria-pressed="{{ $retention['period']['value'] === $value ? 'true' : 'false' }}"
                        wire:key="period-{{ $value }}"
                        wire:click="selectPeriod('{{ $value }}')">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="sub" style="margin-bottom:0.6rem">
            {{ $retention['period']['from']['display'] }} — {{ $retention['period']['to']['display'] }}
        </div>

        <div class="facts">
            <div class="card">
                <div class="n">
                    {{ $retention['return_rate'] === null ? '—' : $retention['return_rate'].'%' }}
                </div>
                <div class="k">{{ __('app.reports.return_rate') }}</div>
            </div>
            <div class="card">
                <div class="n">{{ $retention['returned_count'] }} / {{ $retention['cohort_size'] }}</div>
                <div class="k">{{ __('app.reports.returned') }}</div>
            </div>
            <div class="card">
                <div class="n">{{ $retention['visits_in_period'] }}</div>
                <div class="k">{{ __('app.reports.visits_in_period') }}</div>
            </div>
            <div class="card">
                <div class="n">{{ $retention['total_patients'] }}</div>
                <div class="k">{{ __('app.reports.total_patients') }}</div>
            </div>
        </div>

        <p class="sub" style="margin:0.85rem 0 0">
            {{ __('app.reports.first_visit_only', [
                'once' => $retention['first_visit_only_count'],
                'maturing' => $retention['maturing_count'],
                'days' => $retention['maturity_days'],
            ]) }}
        </p>
    </div>
</div>
