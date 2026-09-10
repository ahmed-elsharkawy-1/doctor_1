<div class="wrap">
    <style>
        .search { position: relative; margin-bottom: 12px; }

        .search input {
            width: 100%;
            padding: 0.7rem 0.9rem;
            border: 1px solid var(--line);
            border-radius: 0.7rem;
            background: var(--card);
            color: var(--ink);
            font: inherit;
        }

        .search input:focus { outline: 2px solid var(--brand); outline-offset: 1px; }

        .plist { display: grid; gap: 0.5rem; }

        .prow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.85rem 1rem;
            text-decoration: none;
            color: inherit;
        }

        .prow:hover { border-color: var(--brand); }
        .prow .name { font-weight: 700; }
        .prow .meta { color: var(--muted); font-size: 0.85rem; }
        .prow .visits { color: var(--muted); font-size: 0.85rem; white-space: nowrap; }
        .empty { padding: 2.5rem 1rem; text-align: center; color: var(--muted); }
        .pager { margin-top: 12px; }
    </style>

    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 12px">{{ __('app.patients.title') }}</h1>

    <div class="search">
        <input type="text" wire:model.live.debounce.300ms="term"
               placeholder="{{ __('app.patients.search_placeholder') }}"
               aria-label="{{ __('app.patients.search_placeholder') }}">
    </div>

    @if ($patients->isEmpty())
        <div class="card empty">
            {{ $term === '' ? __('app.patients.none_yet') : __('app.patients.no_matches') }}
        </div>
    @else
        <div class="plist">
            @foreach ($patients as $patient)
                <a class="card prow" wire:key="p-{{ $patient->id }}"
                   href="{{ route('app.patients.show', $patient) }}">
                    <span>
                        <span class="name">{{ $patient->name }}</span>
                        <span class="meta">
                            · {{ $patient->code }}
                            @if ($patient->phone)
                                · <span dir="ltr">{{ $patient->phone }}</span>
                            @endif
                        </span>
                    </span>

                    <span class="visits">
                        {{ $patient->visits_count }} {{ __('app.patients.visits') }}
                    </span>
                </a>
            @endforeach
        </div>

        <div class="pager">{{ $patients->links() }}</div>
    @endif
</div>
