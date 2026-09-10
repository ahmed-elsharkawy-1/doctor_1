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
        <div class="cols">
            <div class="field">
                <label>{{ __('app.settings.holiday_date') }}</label>
                <input type="date" dir="ltr" wire:model="date">
                @error('date') <div class="err">{{ $message }}</div> @enderror
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
        @foreach ($holidays as $holiday)
            <div class="card" style="padding:0.85rem 1rem;margin-bottom:0.5rem;display:flex;justify-content:space-between;align-items:center;gap:0.75rem"
                 wire:key="h-{{ $holiday->id }}">
                <span>
                    <strong dir="ltr">{{ $holiday->date->format('Y-m-d') }}</strong>
                    @if ($holiday->note) <span class="muted">· {{ $holiday->note }}</span> @endif
                </span>

                <button type="button" class="btn btn-sm btn-danger" wire:click="delete({{ $holiday->id }})">
                    {{ __('app.settings.remove') }}
                </button>
            </div>
        @endforeach
    @endif
</div>
