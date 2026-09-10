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

        <button type="button" class="btn btn-primary" wire:click="save">
            {{ __('app.settings.save') }}
        </button>
    </div>
</div>
