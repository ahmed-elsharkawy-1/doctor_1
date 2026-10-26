<div class="wrap">
    @include('app.partials.settings-nav')

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    <div class="card" style="padding:1.1rem">
        <div class="field">
            <label for="window">{{ __('app.settings.booking_window') }}</label>
            <input id="window" type="number" min="1" max="90" wire:model="bookingWindowDays">
            <small class="muted">{{ __('app.settings.booking_window_hint') }}</small>
            @error('bookingWindowDays') <div class="err">{{ $message }}</div> @enderror
        </div>

        <div class="field">
            <label for="first">{{ __('app.settings.first_visit_only') }}</label>
            <input id="first" type="number" min="1" max="730" wire:model="firstVisitOnlyDays">
            <small class="muted">{{ __('app.settings.first_visit_only_hint') }}</small>
            @error('firstVisitOnlyDays') <div class="err">{{ $message }}</div> @enderror
        </div>

        <div class="field">
            <label>{{ __('app.settings.arrival_lead') }}</label>
            <div class="chips">
                @foreach ($leadOptions as $minutes)
                    <button type="button" class="chip"
                            aria-pressed="{{ $patientArrivalLeadMinutes === $minutes ? 'true' : 'false' }}"
                            wire:click="$set('patientArrivalLeadMinutes', {{ $minutes }})">
                        {{ $minutes }}
                    </button>
                @endforeach
            </div>
            <small class="muted">{{ __('app.settings.arrival_lead_hint') }}</small>
            @error('patientArrivalLeadMinutes') <div class="err">{{ $message }}</div> @enderror
        </div>

        <div class="field">
            <label for="reminder">{{ __('app.settings.reminder_time') }}</label>
            <input id="reminder" type="time" wire:model="reminderTime">
            <small class="muted">{{ __('app.settings.reminder_time_hint') }}</small>
            @error('reminderTime') <div class="err">{{ $message }}</div> @enderror
        </div>

        <div class="field">
            <label for="reminder-position">{{ __('app.settings.reminder_position') }}</label>
            <input id="reminder-position" type="number" min="1" max="50" wire:model="reminderPosition">
            <small class="muted">{{ __('app.settings.reminder_position_hint') }}</small>
            @error('reminderPosition') <div class="err">{{ $message }}</div> @enderror
        </div>

        <div class="field">
            <label>{{ __('app.settings.whatsapp_cancel_cutoff') }}</label>
            <div class="chips">
                @foreach ($cancelCutoffOptions as $minutes)
                    <button type="button" class="chip"
                            aria-pressed="{{ $whatsAppCancelCutoffMinutes === $minutes ? 'true' : 'false' }}"
                            wire:click="$set('whatsAppCancelCutoffMinutes', {{ $minutes }})">
                        {{ $minutes === 0 ? __('app.settings.whatsapp_cancel_cutoff_none') : $minutes }}
                    </button>
                @endforeach
            </div>
            <small class="muted">{{ __('app.settings.whatsapp_cancel_cutoff_hint') }}</small>
            @error('whatsAppCancelCutoffMinutes') <div class="err">{{ $message }}</div> @enderror
        </div>

        <button type="button" class="btn btn-primary" wire:click="save">
            {{ __('app.settings.save') }}
        </button>
    </div>
</div>
