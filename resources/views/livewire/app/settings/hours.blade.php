<div class="wrap">
    @include('app.partials.settings-nav')

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @foreach ($days as $day)
        @php $state = $week[$day->value] ?? ['is_open' => false, 'periods' => []]; @endphp

        <div class="card" style="padding:1rem;margin-bottom:0.6rem" wire:key="day-{{ $day->value }}">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem">
                <strong>{{ $day->label() }}</strong>

                <label style="display:flex;align-items:center;gap:0.5rem">
                    <input type="checkbox" @checked($state['is_open'])
                           wire:click="toggleDay({{ $day->value }})">
                    <span class="muted">{{ $state['is_open'] ? __('app.settings.open') : __('landing.closed') }}</span>
                </label>
            </div>

            @if ($state['is_open'])
                <div style="margin-top:0.75rem">
                    @foreach ($state['periods'] as $index => $period)
                        <div style="display:flex;gap:0.4rem;align-items:center;margin-bottom:0.4rem"
                             wire:key="p-{{ $day->value }}-{{ $index }}">
                            <input type="time" dir="ltr"
                                   wire:model="week.{{ $day->value }}.periods.{{ $index }}.start_time">
                            <span class="muted">–</span>
                            <input type="time" dir="ltr"
                                   wire:model="week.{{ $day->value }}.periods.{{ $index }}.end_time">

                            <button type="button" class="btn btn-sm btn-danger"
                                    wire:click="removePeriod({{ $day->value }}, {{ $index }})">
                                {{ __('app.settings.remove') }}
                            </button>
                        </div>
                    @endforeach

                    @if (count($state['periods']) < $maxPeriods)
                        <button type="button" class="btn btn-sm" wire:click="addPeriod({{ $day->value }})">
                            {{ __('app.settings.add_period') }}
                        </button>
                    @endif
                </div>
            @endif

            <button type="button" class="btn btn-sm btn-primary" style="margin-top:0.75rem"
                    wire:click="saveDay({{ $day->value }})">
                {{ __('app.settings.save_day') }}
            </button>
        </div>
    @endforeach
</div>
