@php
    $money = fn ($amount): string => rtrim(rtrim(number_format((float) $amount, 2, '.', ','), '0'), '.')
        .' '.__('messages.currency');
@endphp

<div class="wrap">
    @include('app.partials.settings-nav')

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;margin-bottom:0.75rem">
        <label style="display:flex;align-items:center;gap:0.5rem">
            <input type="checkbox" wire:model.live="showHidden">
            <span class="muted">{{ __('app.settings.show_hidden') }}</span>
        </label>

        <button type="button" class="btn btn-sm btn-primary" wire:click="startCreating">
            {{ __('app.settings.add_visit_type') }}
        </button>
    </div>

    @if ($editing !== null)
        <div class="card" style="padding:1.1rem;margin-bottom:0.75rem">
            <div class="cols">
                <div class="field">
                    <label>{{ __('app.settings.vt_name') }}</label>
                    <input type="text" wire:model="name">
                    @error('name') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label>{{ __('app.settings.vt_duration') }}</label>
                    <input type="number" min="5" max="480" wire:model="durationMinutes">
                    @error('durationMinutes') <div class="err">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field">
                <label>{{ __('app.settings.vt_description') }}</label>
                <input type="text" wire:model="description">
                @error('description') <div class="err">{{ $message }}</div> @enderror
            </div>

            @if ($canSeePrices)
                <div class="field">
                    <label>{{ __('app.settings.vt_price') }}</label>
                    <input type="number" step="0.01" min="0" wire:model="price">
                    {{-- Changing this never rewrites a past booking. --}}
                    <small class="muted">{{ __('app.settings.vt_price_hint') }}</small>
                    @error('price') <div class="err">{{ $message }}</div> @enderror
                </div>
            @endif

            <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.75rem">
                <input type="checkbox" wire:model="isNewPatientType">
                <span>{{ __('app.settings.vt_new_patient') }}</span>
            </label>

            <div style="display:flex;gap:0.4rem">
                <button type="button" class="btn btn-primary" wire:click="save">
                    {{ __('app.settings.save') }}
                </button>
                <button type="button" class="btn" wire:click="cancelEditing">
                    {{ __('app.actions.dismiss') }}
                </button>
            </div>
        </div>
    @endif

    @foreach ($visitTypes as $visitType)
        <div class="card" style="padding:0.9rem 1rem;margin-bottom:0.5rem;@if (! $visitType->is_active) opacity:.6 @endif"
             wire:key="vt-{{ $visitType->id }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap">
                <span>
                    <strong>{{ $visitType->name }}</strong>
                    @if (! $visitType->is_active)
                        <span class="pill pill-off">{{ __('app.settings.hidden') }}</span>
                    @endif
                    @if ($visitType->is_new_patient_type)
                        <span class="pill">{{ __('app.settings.vt_new_patient') }}</span>
                    @endif
                    <div class="muted" style="font-size:0.85rem">
                        {{ $visitType->duration_minutes }} {{ __('app.patients.minutes') }}
                        @if ($canSeePrices) · {{ $money($visitType->price) }} @endif
                        @if ($visitType->description) · {{ $visitType->description }} @endif
                    </div>
                </span>

                @if ($confirmingHide === $visitType->id)
                    <span style="display:flex;gap:0.4rem;align-items:center">
                        {{-- Hidden, never deleted: bookings reference it for ever. --}}
                        <span class="muted">{{ __('app.settings.hide_confirm') }}</span>
                        <button type="button" class="btn btn-sm btn-danger" wire:click="hide({{ $visitType->id }})">
                            {{ __('app.settings.hide') }}
                        </button>
                        <button type="button" class="btn btn-sm" wire:click="dismissHide">
                            {{ __('app.actions.dismiss') }}
                        </button>
                    </span>
                @else
                    <span style="display:flex;gap:0.4rem">
                        <button type="button" class="btn btn-sm" wire:click="startEditing({{ $visitType->id }})">
                            {{ __('app.settings.edit') }}
                        </button>
                        @if ($visitType->is_active)
                            <button type="button" class="btn btn-sm btn-danger" wire:click="confirmHide({{ $visitType->id }})">
                                {{ __('app.settings.hide') }}
                            </button>
                        @endif
                    </span>
                @endif
            </div>
        </div>
    @endforeach
</div>
