<div class="wrap">
    <style>
        .days { display: flex; gap: 0.4rem; overflow-x: auto; padding-bottom: 0.3rem; margin-bottom: 0.85rem; }
        .day-chip {
            min-width: 4.6rem; text-align: center; line-height: 1.3;
            padding: 0.45rem 0.6rem; border: 1px solid var(--line, #dde3ea); border-radius: 10px;
            background: #fff; font: inherit; cursor: pointer;
        }
        .day-chip small { display: block; font-weight: 500; opacity: 0.8; font-size: 0.75rem; }
        .day-chip[aria-pressed="true"] { background: var(--brand); border-color: var(--brand); color: #fff; }
        .day-chip:disabled { opacity: 0.4; cursor: not-allowed; }

        .list { display: grid; gap: 0.6rem; }
        .entry { padding: 0.9rem 1rem; display: flex; gap: 0.9rem; align-items: flex-start; }
        .entry .rank {
            flex: 0 0 auto; width: 2.2rem; height: 2.2rem; border-radius: 50%;
            display: grid; place-items: center; font-weight: 800;
            background: var(--brand); color: #fff;
        }
        .entry .body { flex: 1 1 auto; min-width: 0; }
        .entry .name { font-weight: 700; }
        .entry .meta { color: var(--muted); font-size: 0.85rem; }
        .entry .acts { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.65rem; }

        .add { padding: 1rem; margin-top: 0.85rem; }
        .add h2 { font-size: 0.95rem; margin: 0 0 0.75rem; }
        .field { margin-bottom: 0.75rem; }
        .field label { display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 0.9rem; }
        .field input, .field select {
            width: 100%; padding: 0.55rem 0.7rem; font: inherit;
            border: 1px solid var(--line, #dde3ea); border-radius: 10px; background: #fff;
        }
        .err { color: var(--danger); font-size: 0.85rem; margin-top: 0.25rem; }

        @media (min-width: 48rem) {
            .cols { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.85rem; align-items: end; }
        }
    </style>

    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 12px">{{ __('waiting_list.app.title') }}</h1>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @unless ($enabled)
        <div class="card empty">{{ __('waiting_list.app.disabled') }}</div>
    @endunless

    <div class="days">
        @foreach ($days as $day)
            <button type="button" class="day-chip"
                    wire:key="day-{{ $day['date']->toDateString() }}"
                    aria-pressed="{{ $date === $day['date']->toDateString() ? 'true' : 'false' }}"
                    @disabled(! $day['is_open'])
                    wire:click="selectDay('{{ $day['date']->toDateString() }}')">
                {{ $day['day']->label() }}
                <small>{{ $day['date']->format('m/d') }}</small>
            </button>
        @endforeach
    </div>

    @if ($entries->isEmpty())
        <div class="card empty">{{ __('waiting_list.app.empty') }}</div>
    @else
        <p class="muted" style="margin:0 0 0.6rem">
            {{ __('waiting_list.app.lead', ['count' => $entries->count(), 'max' => $max]) }}
        </p>

        <div class="list">
            @foreach ($entries as $entry)
                <div class="card entry" wire:key="entry-{{ $entry->id }}">
                    <span class="rank">{{ $loop->iteration }}</span>

                    <div class="body">
                        <div class="name">{{ $entry->patient?->name }}</div>
                        <div class="meta">
                            <span dir="ltr">{{ $entry->patient?->phone }}</span>
                            @if ($entry->visitType) &middot; {{ $entry->visitType->name }} @endif
                            &middot; {{ __('waiting_list.source.'.$entry->source->value) }}
                            &middot; {{ __('waiting_list.app.joined_at', ['time' => $entry->created_at->timezone($this->clinic()->timezone)->format('g:i A')]) }}
                        </div>

                        <div class="acts">
                            {{-- Booking from here carries the entry, which is what
                                 takes the patient off the list. --}}
                            <a class="btn btn-sm btn-primary"
                               href="{{ route('app.bookings.new', ['waiting_list' => $entry->id]) }}">
                                {{ __('waiting_list.app.book') }}
                            </a>

                            @if ($entry->patient?->phone)
                                <a class="btn btn-sm" href="tel:{{ $entry->patient->phone }}">
                                    {{ __('app.actions.call') }}
                                </a>
                            @endif

                            <button type="button" class="btn btn-sm"
                                    wire:click="remove({{ $entry->id }})"
                                    wire:confirm="{{ __('waiting_list.app.remove_confirm', ['name' => $entry->patient?->name]) }}">
                                {{ __('waiting_list.app.remove') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($enabled && $joinable)
        <form class="card add" wire:submit="add">
            <h2>{{ __('waiting_list.app.add_title') }}</h2>

            <div class="cols">
                <div class="field">
                    <label for="wl-name">{{ __('waiting_list.app.patient_name') }}</label>
                    <input id="wl-name" type="text" wire:model="patientName">
                    @error('patientName') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="wl-phone">{{ __('waiting_list.app.phone') }}</label>
                    <input id="wl-phone" type="text" dir="ltr" wire:model="phone">
                    @error('phone') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="wl-type">{{ __('waiting_list.app.visit_type') }}</label>
                    <select id="wl-type" wire:model="visitTypeId">
                        @foreach ($visitTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="add">
                {{ __('waiting_list.app.add') }}
            </button>
        </form>
    @endif
</div>
