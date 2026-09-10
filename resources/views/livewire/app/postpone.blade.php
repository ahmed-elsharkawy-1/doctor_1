@php
    use App\Enums\BookingKind;

    $clock = function ($time) {
        if ($time === null) {
            return __('app.queue.no_time');
        }

        $meridiem = $time->format('A') === 'AM'
            ? __('booking.tracking.am')
            : __('booking.tracking.pm');

        return $time->format('g:i').' '.$meridiem;
    };
@endphp

<div class="wrap">
    <style>
        .pick {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            width: 100%;
            text-align: start;
            border: 0;
            background: none;
            font: inherit;
            color: inherit;
            cursor: pointer;
        }

        .pick + .pick { border-top: 1px solid var(--line); }

        .pick .box {
            flex: 0 0 1.15rem;
            height: 1.15rem;
            border: 2px solid var(--line);
            border-radius: 0.35rem;
        }

        .pick[aria-pressed="true"] .box {
            border-color: var(--brand);
            background: var(--brand);
        }

        .pick .who { flex: 1; }
        .pick .name { font-weight: 700; }
        .pick .meta { color: var(--muted); font-size: 0.85rem; }

        .warn {
            padding: 1rem;
            border: 1px solid var(--danger);
            border-radius: 0.9rem;
            margin-bottom: 0.85rem;
        }

        .warn .names { color: var(--muted); font-size: 0.9rem; margin: 0.5rem 0 0.85rem; }
    </style>

    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 12px">{{ __('app.postpone.title') }}</h1>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @if ($done)
        {{-- Everyone here is now on the call list, which is its own screen. --}}
        <div class="card" style="padding:1.1rem;text-align:center">
            <p style="margin:0 0 0.85rem">{{ __('app.postpone.done_lead') }}</p>
            <a class="btn btn-primary" href="{{ route('app.rebooking') }}">
                {{ __('app.postpone.go_to_call_list') }}
            </a>
        </div>
    @else
        <div class="daybar">
            <button type="button" class="btn btn-sm" wire:click="goToDay(-1)">
                {{ __('app.queue.previous_day') }}
            </button>

            <div class="day">
                {{ $date }}
                <button type="button" class="btn btn-sm" wire:click="today">
                    {{ __('app.queue.today') }}
                </button>
            </div>

            <button type="button" class="btn btn-sm" wire:click="goToDay(1)">
                {{ __('app.queue.next_day') }}
            </button>
        </div>

        @if ($candidates->isEmpty())
            <div class="card empty">
                {{ __('app.postpone.nobody') }}
            </div>
        @else
            <p class="muted" style="margin:0 0 0.6rem">
                {{ __('app.postpone.lead', ['count' => $candidates->count()]) }}
            </p>

            <div class="card" style="padding:0;overflow:hidden;margin-bottom:0.85rem">
                @foreach ($candidates as $booking)
                    @php $picked = in_array($booking->id, $selected, true); @endphp
                    <button type="button" class="pick"
                            aria-pressed="{{ $selected === [] || $picked ? 'true' : 'false' }}"
                            wire:key="candidate-{{ $booking->id }}"
                            wire:click="toggle({{ $booking->id }})">
                        <span class="box"></span>
                        <span class="who">
                            <span class="name">{{ $booking->patient?->name }}</span>
                            <span class="meta" style="display:block">
                                {{ $clock($booking->start_at) }}
                                &middot; {{ $booking->visitType?->name }}
                                &middot; {{ $booking->status->label() }}
                            </span>
                        </span>
                        @if ($booking->booking_kind === BookingKind::EMERGENCY)
                            <span class="pill pill-warn">{{ $booking->booking_kind->label() }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            @if ($confirming)
                <div class="warn">
                    <b>{{ __('app.postpone.confirm', ['count' => $affected->count()]) }}</b>
                    <div class="names">
                        {{ $affected->map(fn ($b) => $b->patient?->name)->filter()->join('، ') }}
                    </div>
                    <button type="button" class="btn btn-danger" wire:click="postpone">
                        {{ __('app.postpone.do_it') }}
                    </button>
                    <button type="button" class="btn" wire:click="dismiss">
                        {{ __('app.actions.dismiss') }}
                    </button>
                </div>
            @else
                <div style="display:flex;gap:0.4rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-danger" wire:click="confirm">
                        {{ __('app.postpone.postpone', ['count' => $affected->count()]) }}
                    </button>

                    @if ($selected !== [])
                        <button type="button" class="btn" wire:click="selectAll">
                            {{ __('app.postpone.select_all') }}
                        </button>
                    @endif
                </div>
            @endif
        @endif
    @endif
</div>
