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

    @if ($canAdd && ! $adding)
        <div style="margin:0 0 0.85rem">
            <button type="button" class="btn btn-sm btn-primary" wire:click="startAdding">
                {{ __('waiting_list.app.add') }}
            </button>
        </div>
    @endif

    @if ($adding)
        {{-- A patient who phoned in. Only for a full day: the service refuses
             one with a free slot for that visit type, and says so here. --}}
        <form class="card" style="padding:1rem;margin:0 0 0.85rem;display:grid;gap:0.6rem" wire:submit="add">
            <strong>{{ __('waiting_list.app.add_title') }}</strong>
            <small class="muted">{{ __('waiting_list.app.add_hint') }}</small>

            <label>
                {{ __('waiting_list.app.add_name') }}
                <input type="text" wire:model="addName" maxlength="255" required>
                @error('addName') <small class="flash-err">{{ $message }}</small> @enderror
            </label>

            <label>
                {{ __('waiting_list.app.add_phone') }}
                <input type="tel" dir="ltr" wire:model="addPhone" maxlength="32" required>
                @error('addPhone') <small class="flash-err">{{ $message }}</small> @enderror
            </label>

            <label>
                {{ __('waiting_list.app.add_visit_type') }}
                <select wire:model="addVisitTypeId" required>
                    <option value="">—</option>
                    @foreach ($visitTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                @error('addVisitTypeId') <small class="flash-err">{{ $message }}</small> @enderror
            </label>

            <div style="display:flex;gap:0.4rem">
                <button type="submit" class="btn btn-sm btn-primary">{{ __('waiting_list.app.add_submit') }}</button>
                <button type="button" class="btn btn-sm" wire:click="cancelAdding">{{ __('app.actions.dismiss') }}</button>
            </div>
        </form>
    @endif

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
</div>
