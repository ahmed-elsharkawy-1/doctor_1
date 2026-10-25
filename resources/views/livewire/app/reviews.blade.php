@php
    $visitLine = fn (?array $visit) => $visit === null
        ? ''
        : __('app.reviews.visit_on', ['day' => $visit['day'], 'date' => $visit['date']['display']]);

    $chips = array_merge(
        [['value' => '', 'display' => __('app.reviews.all')]],
        array_map(fn ($r) => ['value' => $r['value'], 'display' => $r['display']], $summary['ratings']),
    );

    $current = collect($summary['ratings'])->firstWhere('value', $rating);
@endphp

<div class="wrap">
    <style>
        .rv-summary { padding: 1rem; margin-bottom: 0.85rem; }
        .rv-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.85rem; }
        .rv-head b { font-size: 1rem; }
        .rv-percent { font-size: 1.6rem; font-weight: 800; color: var(--brand); }
        .rv-bar { display: grid; grid-template-columns: 7.5rem 1fr 2.5rem; align-items: center; gap: 0.6rem; font-size: 0.85rem; }
        .rv-bar + .rv-bar { margin-top: 0.45rem; }
        .rv-track { height: 0.45rem; background: var(--line); border-radius: 1rem; overflow: hidden; }
        .rv-fill { height: 100%; border-radius: 1rem; }
        .rv-count { text-align: end; color: var(--muted); }

        .rv-very_good { --tone: #16a34a; --tone-soft: #e8f6ec; }
        .rv-good { --tone: #1d5fa8; --tone-soft: #e8f0fa; }
        .rv-bad { --tone: var(--warn); --tone-soft: var(--warn-soft); }
        .rv-fill.rv-very_good, .rv-fill.rv-good, .rv-fill.rv-bad { background: var(--tone); }

        .rv-list-title { font-weight: 700; margin: 0.85rem 0 0.6rem; }

        .rv-card {
            display: block; width: 100%; text-align: start; font: inherit; color: inherit;
            padding: 0.85rem 1rem; margin-bottom: 0.6rem; cursor: pointer;
            background: var(--card); border: 1px solid var(--line); border-radius: 0.9rem;
            border-inline-end: 4px solid var(--tone);
        }
        .rv-card:hover { border-color: var(--tone); }
        .rv-top { display: flex; justify-content: space-between; align-items: center; gap: 0.6rem; }
        .rv-name { font-weight: 700; }
        .rv-meta { color: var(--muted); font-size: 0.82rem; margin-top: 0.15rem; }
        .rv-comment {
            margin-top: 0.45rem; line-height: 1.6;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .rv-more { color: var(--brand); font-size: 0.85rem; margin-top: 0.3rem; display: inline-block; }
        .rv-badge { background: var(--tone-soft); color: var(--tone); border-radius: 1rem; padding: 0.15rem 0.6rem; font-size: 0.78rem; font-weight: 700; white-space: nowrap; }

        .rv-backdrop { position: fixed; inset: 0; background: rgb(16 32 46 / 45%); z-index: 50; display: flex; align-items: flex-end; justify-content: center; }
        .rv-sheet {
            background: var(--card); width: 100%; max-width: 36rem; max-height: 85vh; overflow-y: auto;
            border-radius: 1.2rem 1.2rem 0 0; padding: 1.1rem 1.1rem 1.4rem;
        }
        .rv-sheet h2 { margin: 0.4rem 0 0.1rem; font-size: 1.15rem; }
        .rv-row { display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.55rem 0; }
        .rv-row + .rv-row { border-top: 1px solid var(--line); }
        .rv-row span:first-child { color: var(--muted); }
        .rv-note { border-top: 1px solid var(--line); margin-top: 0.4rem; padding-top: 0.75rem; }
        .rv-note p { margin: 0.35rem 0 0; line-height: 1.7; white-space: pre-line; }
        .rv-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-top: 1rem; }
        .rv-actions .btn { text-align: center; text-decoration: none; }
        .btn-whatsapp { background: #16a34a; border-color: #16a34a; color: #fff; }
        .btn-whatsapp:hover { color: #fff; opacity: 0.92; }
    </style>

    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 12px">{{ __('app.reviews.title') }}</h1>

    <div class="card rv-summary">
        <div class="rv-head">
            <div>
                <b>{{ __('app.reviews.satisfied') }}</b>
                <div class="muted" style="font-size:0.85rem">{{ __('app.reviews.total', ['count' => $summary['total']]) }}</div>
            </div>
            <span class="rv-percent">{{ $summary['satisfied_percent'] === null ? '—' : $summary['satisfied_percent'].'%' }}</span>
        </div>

        @foreach ($summary['ratings'] as $row)
            <div class="rv-bar">
                <span>{{ $row['display'] }}</span>
                <span class="rv-track">
                    <span class="rv-fill rv-{{ $row['value'] }}"
                          style="display:block;width:{{ $summary['total'] > 0 ? round($row['count'] / $summary['total'] * 100, 1) : 0 }}%"></span>
                </span>
                <span class="rv-count">{{ $row['count'] }}</span>
            </div>
        @endforeach
    </div>

    <div class="chips">
        @foreach ($chips as $chip)
            <button type="button" class="chip"
                    aria-pressed="{{ $chip['value'] === $rating ? 'true' : 'false' }}"
                    wire:key="chip-{{ $chip['value'] ?: 'all' }}"
                    wire:click="filter('{{ $chip['value'] }}')">
                {{ $chip['display'] }}
            </button>
        @endforeach
    </div>

    <p class="rv-list-title">
        {{ $current === null
            ? __('app.reviews.count', ['count' => $shown])
            : __('app.reviews.count_of', ['rating' => $current['display'], 'count' => $shown]) }}
    </p>

    @forelse ($items as $item)
        <button type="button" class="rv-card rv-{{ $item['rating']['value'] }}"
                wire:key="review-{{ $item['id'] }}" wire:click="open({{ $item['id'] }})">
            <span class="rv-top">
                <span class="rv-name">{{ $item['patient']['name'] ?? '' }}</span>
                <span class="rv-badge">{{ $item['rating']['display'] }}</span>
            </span>
            <span class="rv-meta" style="display:block">
                {{ __('app.reviews.code', ['code' => $item['patient']['code'] ?? '']) }} &middot; {{ $visitLine($item['visit']) }}
            </span>
            @if ($item['comment'] !== null)
                <span class="rv-comment">{{ $item['comment'] }}</span>
            @endif
            <span class="rv-more">{{ __('app.reviews.read_more') }}</span>
        </button>
    @empty
        <div class="card empty">{{ __('app.reviews.none') }}</div>
    @endforelse

    @if ($hasMore)
        <button type="button" class="btn" style="width:100%" wire:click="loadMore" wire:loading.attr="disabled">
            {{ __('app.reviews.load_more') }}
        </button>
    @endif

    @if ($opened !== null)
        <div class="rv-backdrop" wire:click.self="close" wire:key="sheet-{{ $opened['id'] }}">
            <div class="rv-sheet rv-{{ $opened['rating']['value'] }}" role="dialog" aria-modal="true">
                <button type="button" class="btn btn-sm" wire:click="close" aria-label="{{ __('app.actions.dismiss') }}">✕</button>

                <h2>{{ $opened['patient']['name'] ?? '' }}</h2>
                <div class="muted" style="font-size:0.85rem">{{ __('app.reviews.code', ['code' => $opened['patient']['code'] ?? '']) }}</div>

                <div style="margin-top:0.85rem">
                    <div class="rv-row">
                        <span>{{ __('app.reviews.rating') }}</span>
                        <span class="rv-badge">{{ $opened['rating']['display'] }}</span>
                    </div>
                    @if ($opened['visit'] !== null)
                        <div class="rv-row">
                            <span>{{ __('app.reviews.visit') }}</span>
                            <b>
                                {{ $opened['visit']['day'] }} {{ $opened['visit']['date']['display'] }}
                                @if ($opened['visit']['start_time'] !== null)
                                    &middot; {{ $opened['visit']['start_time']['display'] }}
                                    <x-after-midnight :show="$opened['visit']['after_midnight']" class="muted" />
                                @endif
                            </b>
                        </div>
                        <div class="rv-row">
                            <span>{{ __('app.reviews.visit_type') }}</span>
                            <b>{{ $opened['visit']['visit_type']['name'] }}</b>
                        </div>
                    @endif
                </div>

                @if ($opened['comment'] !== null)
                    <div class="rv-note">
                        <span class="muted">{{ __('app.reviews.note') }}</span>
                        <p>{{ $opened['comment'] }}</p>
                    </div>
                @endif

                @if (($opened['patient']['phone'] ?? null) !== null)
                    @php $phone = $opened['patient']['phone']['value']; @endphp
                    <div class="rv-actions">
                        <a class="btn" href="tel:{{ $phone }}">{{ __('app.reviews.call') }}</a>
                        <a class="btn btn-whatsapp" href="https://wa.me/{{ ltrim($phone, '+') }}" target="_blank" rel="noopener">
                            {{ __('app.reviews.whatsapp') }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
