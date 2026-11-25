<div class="wrap">
    @include('app.partials.settings-nav')

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @if ($branches->count() > 1)
        {{-- The doctor's whole week, one colour per branch (ELA-20). --}}
        @php $palette = ['#0f766e', '#b45309', '#6d28d9', '#be123c', '#1d4ed8', '#4d7c0f']; @endphp
        <div class="card" style="padding:0.9rem 1rem;margin-bottom:0.75rem;overflow-x:auto">
            <strong style="display:block;margin-bottom:0.5rem">{{ __('app.settings.hours_overview') }}</strong>
            <table style="width:100%;border-collapse:collapse;font-size:0.88rem">
                @foreach ($days as $day)
                    <tr style="border-top:1px solid var(--line)">
                        <th scope="row" style="text-align:start;padding:0.35rem 0.5rem 0.35rem 0;white-space:nowrap;font-weight:600">{{ $day->label() }}</th>
                        <td style="padding:0.35rem 0">
                            @php
                                // The doctor's day in time order, whichever branch.
                                $chips = $branches->flatMap(function ($b, $i) use ($overview, $day) {
                                    $row = $overview[$b->id]->firstWhere('day_of_week', $day);

                                    return $row?->is_open
                                        ? $row->periods->map(fn ($p) => ['p' => $p, 'b' => $b, 'i' => $i])
                                        : collect();
                                })->sortBy(fn ($c) => $c['p']->start_time);
                            @endphp
                            @forelse ($chips as $c)
                                <span style="display:inline-block;margin:0.1rem;padding:0.15rem 0.5rem;border-radius:999px;color:#fff;background:{{ $palette[$c['i'] % count($palette)] }}">
                                    <bdi>{{ \App\Support\Wire::time($c['p']->startTime())['display'] }} – {{ \App\Support\Wire::time($c['p']->endTime())['display'] }}</bdi> · {{ $c['b']->name }}
                                </span>
                            @empty
                                <span class="muted">{{ __('app.settings.doctor_off') }}</span>
                            @endforelse
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>

        <div class="field">
            <label for="hours-branch">{{ __('app.settings.hours_for_branch') }}</label>
            <select id="hours-branch" wire:model.live="branchId">
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
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

                        @if (filled($period['start_time']) && filled($period['end_time']) && $period['end_time'] < $period['start_time'])
                            <div class="muted" style="font-size:0.85rem;margin:-0.2rem 0 0.4rem">
                                {{ __('app.settings.ends_next_day') }}
                            </div>
                        @endif
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

            @if ($message = $dayMessages[$day->value] ?? null)
                <div class="flash {{ $message['failed'] ? 'flash-err' : 'flash-ok' }}" style="margin:0.6rem 0 0" role="status">
                    {{ $message['text'] }}
                </div>
                @foreach ($message['warnings'] as $warning)
                    <div class="flash flash-warn" style="margin:0.4rem 0 0;font-weight:500">⚠️ {{ $warning }}</div>
                @endforeach
            @endif
        </div>
    @endforeach
</div>
