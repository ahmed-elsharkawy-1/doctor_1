@php
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
        .call { padding: 0.9rem 1rem; }

        .call header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .call .name { font-weight: 700; }
        .call .meta { color: var(--muted); font-size: 0.85rem; }

        .call .acts { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.75rem; }

        .call.is-contacted { opacity: 0.62; }

        .list { display: grid; gap: 0.6rem; }

        @media (min-width: 48rem) {
            .list { grid-template-columns: 1fr 1fr; }
        }
    </style>

    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 12px">{{ __('app.rebooking.title') }}</h1>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @if ($bookings->isEmpty())
        <div class="card empty">{{ __('app.rebooking.empty') }}</div>
    @else
        <p class="muted" style="margin:0 0 0.6rem">
            {{ __('app.rebooking.lead', ['count' => $bookings->count()]) }}
        </p>

        <div class="list">
            @foreach ($bookings as $booking)
                <div class="card call @if ($booking->contacted_at !== null) is-contacted @endif"
                     wire:key="rebooking-{{ $booking->id }}">
                    <header>
                        <div>
                            <div class="name">{{ $booking->patient?->name }}</div>
                            <div class="meta">
                                {{ $booking->start_at?->toDateString() }}
                                &middot; {{ $clock($booking->start_at) }}
                                &middot; {{ $booking->visitType?->name }}
                            </div>
                        </div>

                        @if ($booking->contacted_at !== null)
                            <span class="pill">{{ __('app.rebooking.contacted') }}</span>
                        @endif
                    </header>

                    <div class="acts">
                        {{-- Booking from here carries the original's id, which is
                             what takes this patient off the list. --}}
                        <a class="btn btn-sm btn-primary"
                           href="{{ route('app.bookings.new', ['rebooking_for' => $booking->id]) }}">
                            {{ __('app.rebooking.book_again') }}
                        </a>

                        @if ($booking->contacted_at === null)
                            <button type="button" class="btn btn-sm"
                                    wire:click="markContacted({{ $booking->id }})">
                                {{ __('app.rebooking.mark_contacted') }}
                            </button>
                        @endif

                        @if ($booking->patient?->phone)
                            <a class="btn btn-sm" href="tel:{{ $booking->patient->phone }}">
                                {{ __('app.actions.call') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
