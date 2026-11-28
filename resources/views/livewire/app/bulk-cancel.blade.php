<div class="wrap">
    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 4px">{{ __('app.bulk_cancel.title') }}</h1>
    <p class="muted" style="margin:0 0 12px">{{ __('app.bulk_cancel.lead') }}</p>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    {{-- The same scope as the delay: branches, then days. --}}
    <div class="card" style="padding:1rem;margin-bottom:0.85rem">
        @if ($branches->count() > 1)
            <div class="chips" style="margin-bottom:0.75rem">
                @foreach ($branches as $b)
                    <label class="chip" style="display:inline-flex;align-items:center;gap:0.4rem" wire:key="scope-{{ $b->id }}">
                        <input type="checkbox" value="{{ $b->id }}" wire:model.live="scope"> {{ $b->name }}
                    </label>
                @endforeach
                <button type="button" class="chip" wire:click="allBranches">{{ __('app.delay.all_branches') }}</button>
            </div>
        @endif
        <div class="cols">
            <div class="field">
                <label for="bc-from">{{ __('app.bulk_cancel.from') }}</label>
                <input id="bc-from" type="date" dir="ltr" min="{{ $today }}" wire:model.live="from">
            </div>
            <div class="field">
                <label for="bc-to">{{ __('app.bulk_cancel.to') }}</label>
                <input id="bc-to" type="date" dir="ltr" min="{{ $today }}" wire:model.live="to">
            </div>
        </div>
    </div>

    @php $total = 0; @endphp
    @foreach ($groups as $branchId => $bookings)
        @php
            $b = $branches->firstWhere('id', $branchId);
            $count = $bookings->reject(fn ($bk) => in_array((string) $bk->id, $excluded, true))->count();
            $total += $count;
        @endphp
        <div class="card" style="padding:1rem;margin-bottom:0.6rem" wire:key="g-{{ $branchId }}">
            <h2 style="font-size:0.95rem;font-weight:700;margin:0 0 0.6rem">🏥 {{ $b?->name }} &middot; {{ trans_choice('app.delay.count', $count, ['count' => $count]) }}</h2>
            @forelse ($bookings as $booking)
                <label style="display:flex;align-items:center;gap:0.6rem;min-height:44px;border-top:1px solid var(--line)" wire:key="bk-{{ $booking->id }}">
                    <input type="checkbox" @checked(! in_array((string) $booking->id, $excluded, true)) wire:click="toggleExcluded({{ $booking->id }})">
                    <span dir="ltr" style="white-space:nowrap">{{ $booking->visit_date->format('Y-m-d') }}</span>
                    <span style="white-space:nowrap">{{ $booking->start_at ? \App\Support\Wire::time($booking->start_at)['display'] : __('app.queue.no_time') }}</span>
                    <span class="muted" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $booking->patient?->name }}</span>
                </label>
            @empty
                <p class="muted" style="margin:0">{{ __('app.bulk_cancel.none') }}</p>
            @endforelse
        </div>
    @endforeach

    @if ($total > 0)
        @if ($confirming)
            <div class="flash flash-err" style="font-weight:400">
                {{-- e.g. "Cancel 12: Gedeila 5 · Al-Tur'a 7". --}}
                <b>{{ __('app.bulk_cancel.summary', ['count' => $total]) }}</b>
                {{ $groups->map(function ($bookings, $id) use ($branches, $excluded) {
                    $n = $bookings->reject(fn ($bk) => in_array((string) $bk->id, $excluded, true))->count();
                    return $n > 0 ? $branches->firstWhere('id', $id)?->name.' '.$n : null;
                })->filter()->join(' · ') }}
                <p style="margin:0.5rem 0">{{ __('app.bulk_cancel.what_happens') }}</p>
                <button type="button" class="btn btn-sm btn-danger-solid" wire:click="cancel" wire:loading.attr="disabled">{{ __('app.bulk_cancel.do_it') }}</button>
                <button type="button" class="btn btn-sm" wire:click="dismiss">{{ __('app.settings.cancel') }}</button>
            </div>
        @else
            <button type="button" class="btn btn-primary" style="width:100%" wire:click="confirm">
                {{ __('app.bulk_cancel.submit', ['count' => $total]) }}
            </button>
        @endif
    @endif
</div>
