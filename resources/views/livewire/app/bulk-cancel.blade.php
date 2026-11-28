<div class="wrap">
    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 4px">{{ __('app.bulk_cancel.title') }}</h1>
    <p class="muted" style="margin:0 0 12px">{{ __('app.bulk_cancel.lead') }}</p>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif
    @if ($dateError !== null)
        <div class="flash flash-err">{{ $dateError }}</div>
    @endif

    {{-- The same scope as the delay: branches, then days. --}}
    <div class="card" style="padding:1rem;margin-bottom:0.85rem">
        @if ($branches->count() > 1)
            @php $everyBranch = $branches->every(fn ($b) => in_array((string) $b->id, $scope, true)); @endphp
            <div class="chips" style="margin-bottom:0.75rem">
                @foreach ($branches as $b)
                    <label class="chip" style="display:inline-flex;align-items:center;gap:0.4rem" wire:key="scope-{{ $b->id }}">
                        <input type="checkbox" value="{{ $b->id }}" wire:model.live="scope" wire:loading.attr="disabled" wire:target="cancel"> {{ $b->name }}
                    </label>
                @endforeach
                <button type="button" class="chip" aria-pressed="{{ $everyBranch ? 'true' : 'false' }}" wire:click="allBranches" wire:loading.attr="disabled" wire:target="cancel">{{ __('app.delay.all_branches') }}</button>
            </div>
        @endif
        <div class="cols">
            <div class="field">
                <label for="bc-from">{{ __('app.bulk_cancel.from') }}</label>
                <input id="bc-from" type="date" dir="ltr" min="{{ $today }}" wire:model.live="from" wire:loading.attr="disabled" wire:target="cancel">
            </div>
            <div class="field">
                <label for="bc-to">{{ __('app.bulk_cancel.to') }}</label>
                <input id="bc-to" type="date" dir="ltr" min="{{ $from ?: $today }}" wire:model.live="to" wire:loading.attr="disabled" wire:target="cancel">
            </div>
        </div>
    </div>

    @if ($branches->count() > 1 && $scope === [])
        <p class="muted" style="margin:0 0 0.85rem">{{ __('app.bulk_cancel.pick_branch') }}</p>
    @endif

    @php $total = 0; @endphp
    @foreach ($groups as $branchId => $bookings)
        @php
            $b = $branches->firstWhere('id', $branchId);
            $count = $bookings->reject(fn ($bk) => in_array((string) $bk->id, $excluded, true))->count();
            $total += $count;
        @endphp
        <div class="card" style="padding:1rem;margin-bottom:0.6rem" wire:key="g-{{ $branchId }}">
            <h2 style="font-size:0.95rem;font-weight:700;margin:0 0 0.6rem">🏥 {{ $b?->name }} &middot; {{ trans_choice('app.bulk_cancel.count', $count, ['count' => $count, 'of' => $bookings->count()]) }}</h2>
            @forelse ($bookings->groupBy(fn ($bk) => $bk->visit_date->toDateString()) as $day => $onDay)
                <h3 class="muted" style="font-size:0.85rem;font-weight:600;margin:0.6rem 0 0.2rem">{{ \Illuminate\Support\Carbon::parse($day)->locale(app()->getLocale())->isoFormat('dddd D MMMM') }}</h3>
                @foreach ($onDay as $booking)
                    @php $kept = in_array((string) $booking->id, $excluded, true); @endphp
                    {{-- The key carries the tick, so a morph never leaves a box showing the opposite of what will happen. --}}
                    <label style="display:flex;align-items:center;gap:0.6rem;min-height:44px;border-top:1px solid var(--line)" wire:key="bk-{{ $booking->id }}-{{ $kept ? 'kept' : 'on' }}">
                        <input type="checkbox" @checked(! $kept) wire:click="toggleExcluded({{ $booking->id }})" wire:loading.attr="disabled" wire:target="cancel">
                        <span style="white-space:nowrap;min-width:4.5rem;font-variant-numeric:tabular-nums">{{ $booking->start_at ? \App\Support\Wire::time($booking->start_at)['display'] : __('app.queue.no_time') }}</span>
                        <span class="muted" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $booking->patient?->name }}</span>
                        @if ($booking->status === \App\Enums\BookingStatus::ARRIVED)
                            <span class="chip" style="white-space:nowrap">{{ __('app.bulk_cancel.arrived') }}</span>
                        @endif
                    </label>
                @endforeach
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
                <button type="button" class="btn btn-sm btn-danger-solid" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel">
                    <span wire:loading.remove wire:target="cancel">{{ __('app.bulk_cancel.do_it') }}</span>
                    <span wire:loading wire:target="cancel">{{ __('app.bulk_cancel.working') }}</span>
                </button>
                <button type="button" class="btn btn-sm" wire:click="dismiss" wire:loading.attr="disabled" wire:target="cancel">{{ __('app.bulk_cancel.back') }}</button>
            </div>
        @else
            <button type="button" class="btn btn-danger" style="width:100%" wire:click="confirm" wire:loading.attr="disabled">
                {{ trans_choice('app.bulk_cancel.submit', $total, ['count' => $total]) }}
            </button>
        @endif
    @endif
</div>
