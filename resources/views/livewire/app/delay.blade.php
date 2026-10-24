@php
    $clock = function ($time) {
        $meridiem = $time->format('A') === 'AM'
            ? __('booking.tracking.am')
            : __('booking.tracking.pm');

        return $time->format('g:i').' '.$meridiem;
    };

    $delayLabel = collect($options)->firstWhere('minutes', $minutes)['label'] ?? '';
@endphp

<div class="wrap">
    <style>
        .section { padding: 1rem; margin-bottom: 0.85rem; }
        .section h2 { font-size: 0.95rem; font-weight: 700; margin: 0 0 0.6rem; color: var(--brand); }

        .times { display: grid; gap: 0.4rem; }

        .time {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--line);
            border-radius: 0.6rem;
            background: var(--card);
            font: inherit;
            color: inherit;
            text-align: start;
            cursor: pointer;
        }

        .time b { font-size: 1rem; }
        .time .meta { color: var(--muted); font-size: 0.85rem; }
        .time[aria-pressed="true"] { background: var(--brand); border-color: var(--brand); color: #fff; }
        .time[aria-pressed="true"] .meta { color: #fff; opacity: 0.85; }

        .warn {
            padding: 1rem;
            border: 1px solid var(--warn);
            border-radius: 0.9rem;
            margin-bottom: 0.85rem;
        }

        .moved { display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.7rem 1rem; }
        .moved + .moved { border-top: 1px solid var(--line); }
        .moved .name { font-weight: 700; }
        .moved .meta { color: var(--muted); font-size: 0.85rem; }
        .moved .new { font-weight: 700; white-space: nowrap; text-align: end; }
    </style>

    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 4px">{{ __('app.delay.title') }}</h1>
    <p class="muted" style="margin:0 0 12px">
        {{ __('app.delay.today', ['date' => $today->copy()->locale(app()->getLocale())->isoFormat('dddd D MMMM')]) }}
    </p>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @if ($result !== null)
        <div class="card" style="padding:0;overflow:hidden;margin-bottom:0.85rem">
            @foreach ($result['items'] as $item)
                <div class="moved" wire:key="moved-{{ $item['booking_id'] }}">
                    <div>
                        <div class="name">{{ $item['patient']['name'] ?? '' }}</div>
                        <div class="meta">
                            {{ __('app.delay.was', ['time' => $item['previous_start_time']['display']]) }}
                            &middot; {{ $item['delay']['label'] }}
                        </div>
                    </div>
                    <div class="new">
                        {{ $item['start_time']['display'] }}
                        <x-after-midnight :show="$item['after_midnight']" style="display:block" class="meta" />
                    </div>
                </div>
            @endforeach
        </div>

        <p class="muted" style="margin:0 0 0.85rem">
            {{ $result['messaging']['sent_count'] > 0
                ? __('app.delay.messages_sent', ['count' => $result['messaging']['sent_count']])
                : __('app.delay.messages_none') }}
        </p>

        <div style="display:flex;gap:0.4rem;flex-wrap:wrap">
            <a class="btn btn-primary" href="{{ route('app.queue') }}">{{ __('app.delay.back_to_queue') }}</a>
            <button type="button" class="btn" wire:click="again">{{ __('app.delay.another') }}</button>
        </div>
    @elseif ($startingPoints->isEmpty())
        <div class="card empty">{{ __('app.delay.nobody') }}</div>
    @else
        <div class="card section">
            <h2>{{ __('app.delay.how_long') }}</h2>
            <div class="chips">
                @foreach ($options as $option)
                    <button type="button" class="chip"
                            aria-pressed="{{ $option['minutes'] === $minutes ? 'true' : 'false' }}"
                            wire:key="minutes-{{ $option['minutes'] }}"
                            wire:click="pickMinutes({{ $option['minutes'] }})">
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="card section">
            <h2>{{ __('app.delay.from') }}</h2>
            <p class="muted" style="margin:0 0 0.6rem">{{ __('app.delay.from_hint') }}</p>
            <div class="times">
                @foreach ($startingPoints as $booking)
                    <button type="button" class="time"
                            aria-pressed="{{ $booking->id === $from ? 'true' : 'false' }}"
                            wire:key="from-{{ $booking->id }}"
                            wire:click="pickFrom({{ $booking->id }})">
                        <span>
                            <b>{{ $clock($booking->start_at) }}</b>
                            <x-after-midnight :show="$booking->startsAfterMidnight()" class="meta" />
                        </span>
                        <span class="meta">
                            {{ $booking->patient?->name }} &middot; {{ $booking->status->label() }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        @if ($confirming && $picked !== null)
            <div class="warn">
                <b>{{ __('app.delay.confirm', [
                    'name' => $picked->patient?->name,
                    'time' => $clock($picked->start_at),
                    'delay' => $delayLabel,
                ]) }}</b>
                <p class="muted" style="margin:0.5rem 0 0.85rem">{{ __('app.delay.gaps') }}</p>
                <button type="button" class="btn btn-primary" wire:click="delay" wire:loading.attr="disabled">
                    {{ __('app.delay.do_it') }}
                </button>
                <button type="button" class="btn" wire:click="dismiss">{{ __('app.actions.dismiss') }}</button>
            </div>
        @else
            <button type="button" class="btn btn-primary" style="width:100%" wire:click="confirm">
                {{ __('app.delay.submit') }}
            </button>
        @endif
    @endif
</div>
