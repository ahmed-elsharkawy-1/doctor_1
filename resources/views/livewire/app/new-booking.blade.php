@php
    use App\Enums\BookingKind;

    $clinic = $this->clinic();
    $emergency = $this->isEmergency();

    $clock = function ($time) {
        $meridiem = $time->format('A') === 'AM'
            ? __('booking.tracking.am')
            : __('booking.tracking.pm');

        return $time->format('g:i').' '.$meridiem;
    };
@endphp

<div class="wrap">
    <style>
        .section { padding: 1rem; margin-bottom: 0.85rem; }
        .section > h2 {
            margin: 0 0 0.65rem;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 0.02em;
        }

        .chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }

        .chip {
            border: 1px solid var(--line);
            background: var(--card);
            color: var(--ink);
            border-radius: 0.6rem;
            padding: 0.45rem 0.8rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .chip[aria-pressed="true"] { background: var(--brand); border-color: var(--brand); color: #fff; }
        .chip:disabled { opacity: 0.4; cursor: not-allowed; text-decoration: line-through; }

        .days { display: flex; gap: 0.4rem; overflow-x: auto; padding-bottom: 0.3rem; }
        .day-chip { min-width: 4.6rem; text-align: center; line-height: 1.3; }
        .day-chip small { display: block; font-weight: 500; opacity: 0.8; font-size: 0.75rem; }

        .slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(5.2rem, 1fr)); gap: 0.4rem; }

        .field { margin-bottom: 0.75rem; }
        .field label { display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 0.9rem; }

        .field input[type="text"],
        .field input[type="number"],
        .field textarea {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--line);
            border-radius: 0.6rem;
            background: var(--card);
            color: var(--ink);
            font: inherit;
        }

        .results { margin-top: 0.6rem; display: grid; gap: 0.35rem; }

        .result {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            width: 100%;
            text-align: start;
            border: 1px solid var(--line);
            background: var(--card);
            color: var(--ink);
            border-radius: 0.6rem;
            padding: 0.6rem 0.8rem;
            font: inherit;
            cursor: pointer;
        }

        .result:hover { border-color: var(--brand); }
        .err { color: var(--danger); font-size: 0.85rem; margin-top: 0.25rem; }
        .chosen { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }

        @media (min-width: 48rem) {
            .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; align-items: start; }
            .cols > * { margin-bottom: 0; }
        }
    </style>

    <div class="topbar">
        <div>
            <h1>{{ __('app.booking.title') }}</h1>
            <div class="who">{{ $clinic->name }}</div>
        </div>

        <a class="btn btn-sm" href="{{ route('app.queue') }}">{{ __('app.booking.back_to_queue') }}</a>
    </div>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">
            {{ $notice }}

            @if ($trackingUrl !== null && ! $failed)
                <div style="margin-top:0.5rem;font-weight:600">
                    <button type="button" class="btn btn-sm"
                            onclick="navigator.clipboard.writeText('{{ $trackingUrl }}');
                                     this.textContent='{{ __('app.actions.link_copied') }}'">
                        {{ __('app.actions.copy_link') }}
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- Patient --}}
    <div class="card section">
        <h2>{{ __('app.booking.patient') }}</h2>

        @if ($patientId !== null)
            <div class="chosen">
                <div>
                    <strong>{{ $patientName }}</strong>
                    <div class="muted" dir="ltr" style="text-align:start">{{ $phone }}</div>
                </div>
                <button type="button" class="btn btn-sm" wire:click="clearPatient">
                    {{ __('app.booking.change_patient') }}
                </button>
            </div>
        @else
            <div class="field">
                <label for="search">{{ __('app.booking.search_patient') }}</label>
                <input id="search" type="text" wire:model.live.debounce.300ms="patientSearch"
                       placeholder="{{ __('app.booking.search_patient') }}">
            </div>

            @if ($patients->isNotEmpty())
                <div class="results">
                    @foreach ($patients as $patient)
                        <button type="button" class="result" wire:key="patient-{{ $patient->id }}"
                                wire:click="selectPatient({{ $patient->id }})">
                            <span>
                                <strong>{{ $patient->name }}</strong>
                                <span class="muted"> · {{ $patient->code }}</span>
                            </span>
                            <span class="muted">{{ $patient->visits_count }} {{ __('app.booking.visits') }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="cols" style="margin-top:0.75rem">
                <div class="field">
                    <label for="name">{{ __('app.booking.patient_name') }}</label>
                    <input id="name" type="text" wire:model="patientName">
                    @error('patientName') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="phone">{{ __('app.booking.phone') }}</label>
                    <input id="phone" type="text" wire:model="phone" dir="ltr">
                    @error('phone') <div class="err">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field">
                <label for="age">{{ __('app.booking.age') }}</label>
                <input id="age" type="number" min="0" max="130" wire:model="age">
                @error('age') <div class="err">{{ $message }}</div> @enderror
            </div>

            <label class="check" style="display:flex;gap:0.5rem;align-items:center">
                <input type="checkbox" wire:model="whatsappOptIn">
                <span>{{ __('app.booking.whatsapp_opt_in') }}</span>
            </label>
        @endif
    </div>

    {{-- Visit type --}}
    <div class="card section">
        <h2>{{ __('app.booking.visit_type') }}</h2>
        <div class="chips">
            @foreach ($visitTypes as $visitType)
                <button type="button" class="chip" wire:key="vt-{{ $visitType->id }}"
                        aria-pressed="{{ $visitTypeId === $visitType->id ? 'true' : 'false' }}"
                        wire:click="selectVisitType({{ $visitType->id }})">
                    {{ $visitType->name }}
                </button>
            @endforeach
        </div>
        @error('visitTypeId') <div class="err">{{ $message }}</div> @enderror
    </div>

    {{-- Normal or emergency --}}
    <div class="card section">
        <h2>{{ __('app.booking.kind') }}</h2>
        <div class="chips">
            @foreach (BookingKind::cases() as $case)
                <button type="button" class="chip" wire:key="kind-{{ $case->value }}"
                        aria-pressed="{{ $kind === $case->value ? 'true' : 'false' }}"
                        wire:click="selectKind('{{ $case->value }}')">
                    {{ $case->label() }}
                </button>
            @endforeach
        </div>

        @if ($emergency)
            <p class="muted" style="margin:0.6rem 0 0">{{ __('app.booking.emergency_note') }}</p>

            <h2 style="margin-top:0.85rem">{{ __('app.booking.patient_location') }}</h2>
            <div class="chips">
                @foreach ($locations as $location)
                    <button type="button" class="chip" wire:key="loc-{{ $location->value }}"
                            aria-pressed="{{ $patientLocation === $location->value ? 'true' : 'false' }}"
                            wire:click="$set('patientLocation', '{{ $location->value }}')">
                        {{ $location->label() }}
                    </button>
                @endforeach
            </div>
            @error('patientLocation') <div class="err">{{ $message }}</div> @enderror
        @endif
    </div>

    {{-- Day and time: an emergency takes neither. --}}
    @unless ($emergency)
        <div class="card section">
            <h2>{{ __('app.booking.day') }}</h2>
            <div class="days">
                @foreach ($days as $day)
                    <button type="button" class="chip day-chip"
                            wire:key="day-{{ $day['date']->toDateString() }}"
                            aria-pressed="{{ $date === $day['date']->toDateString() ? 'true' : 'false' }}"
                            @disabled(! $day['is_open'])
                            wire:click="selectDay('{{ $day['date']->toDateString() }}')">
                        {{ $day['day']->label() }}
                        <small>{{ $day['date']->format('m/d') }}</small>
                    </button>
                @endforeach
            </div>
            @error('date') <div class="err">{{ $message }}</div> @enderror
        </div>

        <div class="card section">
            <h2>{{ __('app.booking.slot') }}</h2>

            @if ($availability === null || ! $availability->isOpen)
                <p class="muted" style="margin:0">
                    {{ $availability?->closedReason?->label() ?? __('app.booking.closed') }}
                </p>
            @elseif ($availability->availableCount() === 0)
                <p class="muted" style="margin:0">{{ __('app.booking.no_slots') }}</p>
            @else
                <div class="slots">
                    @foreach ($availability->slots as $slot)
                        <button type="button" class="chip"
                                wire:key="slot-{{ $slot->startAt->format('Hi') }}"
                                aria-pressed="{{ $startTime === $slot->startAt->format('H:i') ? 'true' : 'false' }}"
                                @disabled(! $slot->isAvailable)
                                wire:click="selectSlot('{{ $slot->startAt->format('H:i') }}')">
                            {{ $clock($slot->startAt) }}
                        </button>
                    @endforeach
                </div>
            @endif

            @error('startTime') <div class="err">{{ $message }}</div> @enderror
        </div>
    @endunless

    {{-- Notes and save --}}
    <div class="card section">
        <div class="field">
            <label for="notes">{{ __('app.booking.notes') }}</label>
            <textarea id="notes" rows="2" wire:model="notes"></textarea>
            @error('notes') <div class="err">{{ $message }}</div> @enderror
        </div>

        <button type="button" class="btn btn-primary" style="width:100%" wire:click="save"
                wire:loading.attr="disabled">
            {{ __('app.booking.save') }}
        </button>
    </div>
</div>
