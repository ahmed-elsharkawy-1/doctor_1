<div class="wrap">
    @include('app.partials.settings-nav')

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">
            {{ $notice }}

            @if ($bookingsOnDate !== null)
                {{-- The service refused because patients are booked that day.
                     Say how many, then let her decide. --}}
                <div style="margin-top:0.6rem;display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap">
                    <span>{{ __('app.settings.holiday_force_prompt', ['count' => $bookingsOnDate]) }}</span>
                    <button type="button" class="btn btn-sm btn-danger" wire:click="forceAdd">
                        {{ __('app.settings.holiday_force') }}
                    </button>
                    <button type="button" class="btn btn-sm" wire:click="dismissForce">
                        {{ __('app.actions.dismiss') }}
                    </button>
                </div>
            @endif
        </div>
    @endif

    <div class="card" style="padding:1.1rem;margin-bottom:0.75rem">
        @if ($branches->count() > 1)
            {{-- First field: which branches close, every one by default (ELA-20). --}}
            <fieldset style="border:0;padding:0;margin:0 0 0.8rem">
                <legend style="font-weight:600;margin-bottom:0.3rem">{{ __('app.settings.holiday_scope') }}</legend>
                <div style="display:flex;flex-wrap:wrap;gap:0.25rem 1.5rem">
                    <label style="display:flex;align-items:center;gap:0.5rem;min-height:44px">
                        <input type="radio" value="all" wire:model.live="scope"> {{ __('app.settings.holiday_all_branches') }}
                    </label>
                    <label style="display:flex;align-items:center;gap:0.5rem;min-height:44px">
                        <input type="radio" value="some" wire:model.live="scope"> {{ __('app.settings.holiday_some_branches') }}
                    </label>
                </div>
                @if ($scope === 'some')
                    <div style="padding-inline-start:1.6rem">
                        @foreach ($branches as $b)
                            <label style="display:flex;align-items:center;gap:0.5rem;min-height:36px">
                                <input type="checkbox" value="{{ $b->id }}" wire:model="branchIds"> {{ $b->name }}
                            </label>
                        @endforeach
                    </div>
                    @error('branchIds') <div class="err">{{ $message }}</div> @enderror
                @endif
            </fieldset>
        @endif

        <div class="cols">
            <div class="field">
                <label>{{ __('app.settings.holiday_date') }}</label>
                <input type="date" dir="ltr" wire:model="date">
                @error('date') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label>{{ __('app.settings.holiday_end_date') }}</label>
                <input type="date" dir="ltr" wire:model="endDate">
                @error('endDate') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label>{{ __('app.settings.holiday_note') }}</label>
                <input type="text" wire:model="note">
                @error('note') <div class="err">{{ $message }}</div> @enderror
            </div>
        </div>

        <button type="button" class="btn btn-primary" wire:click="add">
            {{ __('app.settings.add_holiday') }}
        </button>
    </div>

    <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.6rem">
        <input type="checkbox" wire:model.live="showPast">
        <span class="muted">{{ __('app.settings.show_past') }}</span>
    </label>

    @if ($holidays->isEmpty())
        <div class="card" style="padding:2rem;text-align:center;color:var(--muted)">
            {{ __('app.settings.no_holidays') }}
        </div>
    @else
        @foreach ($holidays as $group)
            @php
                $first = $group->first();
                $from = $group->min(fn ($h) => $h->date->format('Y-m-d'));
                $to = $group->max(fn ($h) => $h->date->format('Y-m-d'));
                $names = $group->pluck('branch.name')->filter()->unique()->join('، ');
            @endphp
            <div class="card" style="padding:0.85rem 1rem;margin-bottom:0.5rem;display:flex;justify-content:space-between;align-items:center;gap:0.75rem"
                 wire:key="h-{{ $first->id }}">
                <span>
                    @if ($to !== $from)
                        <strong>{!! __('app.settings.holiday_range', ['from' => '<bdi dir="ltr" style="white-space:nowrap">'.e($from).'</bdi>', 'to' => '<bdi dir="ltr" style="white-space:nowrap">'.e($to).'</bdi>']) !!}</strong>
                    @else
                        <strong dir="ltr">{{ $from }}</strong>
                    @endif
                    @if ($branches->count() > 1)
                        <span class="pill">{{ $group->contains(fn ($h) => $h->branch_id === null) ? __('app.settings.holiday_all_branches') : $names }}</span>
                    @endif
                    @if ($first->note) <span class="muted">· {{ $first->note }}</span> @endif
                </span>

                <button type="button" class="btn btn-sm btn-danger" wire:click="delete({{ $first->id }})">
                    {{ __('app.settings.remove') }}
                </button>
            </div>
        @endforeach
    @endif
</div>
