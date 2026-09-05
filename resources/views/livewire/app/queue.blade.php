@php
    use App\Enums\BookingKind;
    use App\Enums\BookingStatus;

    $clinic = $this->clinic();

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
        .daybar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.85rem;
        }

        .daybar .day { font-weight: 700; }

        .tallies { display: flex; gap: 0.5rem; margin-bottom: 0.85rem; flex-wrap: wrap; }

        .tally {
            flex: 1 1 6rem;
            padding: 0.7rem;
            text-align: center;
        }

        .tally .n { font-size: 1.4rem; font-weight: 700; }
        .tally .k { color: var(--muted); font-size: 0.8rem; }

        .queue { display: grid; gap: 0.6rem; }

        .booking { padding: 0.9rem 1rem; }

        .booking header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .booking .name { font-weight: 700; }
        .booking .meta { color: var(--muted); font-size: 0.85rem; }

        .booking .acts {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
        }

        .booking.is-terminal { opacity: 0.62; }

        .empty { padding: 2.5rem 1rem; text-align: center; color: var(--muted); }

        /* One layout, widened. On a desk monitor the cards sit two abreast
           rather than becoming a different screen. */
        @media (min-width: 48rem) {
            .queue { grid-template-columns: 1fr 1fr; }
            .tally { flex: 1; }
        }
    </style>

    <div class="topbar">
        <div>
            <h1>{{ $clinic->name }}</h1>
            <div class="who">{{ auth()->user()->name }}</div>
        </div>

        <div style="display:flex;gap:0.4rem;align-items:center">
            <a class="btn btn-sm btn-primary" href="{{ route('app.bookings.new') }}">
                {{ __('app.booking.new') }}
            </a>

            <form method="POST" action="{{ route('app.logout') }}">
                @csrf
                <button type="submit" class="btn btn-sm">{{ __('app.queue.sign_out') }}</button>
            </form>
        </div>
    </div>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

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

    <div class="tallies">
        <div class="card tally">
            <div class="n">{{ $counts['total'] }}</div>
            <div class="k">{{ __('app.queue.total') }}</div>
        </div>
        <div class="card tally">
            <div class="n">{{ $counts['waiting'] }}</div>
            <div class="k">{{ __('app.queue.waiting') }}</div>
        </div>
        <div class="card tally">
            <div class="n">{{ $counts['done'] }}</div>
            <div class="k">{{ __('app.queue.done') }}</div>
        </div>
    </div>

    @if ($bookings->isEmpty())
        <div class="card empty">{{ __('app.queue.empty') }}</div>
    @else
        <div class="queue">
            @foreach ($bookings as $booking)
                <div class="card booking @if ($booking->status->isTerminal()) is-terminal @endif"
                     wire:key="booking-{{ $booking->id }}">
                    <header>
                        <div>
                            <div class="name">{{ $booking->patient?->name }}</div>
                            <div class="meta">
                                {{ $clock($booking->start_at) }}
                                &middot; {{ $booking->visitType?->name }}
                                &middot; {{ $booking->patient?->code }}
                            </div>
                        </div>

                        <div style="text-align:end">
                            <span class="pill @if ($booking->status->isTerminal() && $booking->status !== BookingStatus::DONE) pill-off @endif">
                                {{ $booking->status->label() }}
                            </span>

                            @if ($booking->booking_kind === BookingKind::EMERGENCY)
                                <div style="margin-top:0.3rem">
                                    <span class="pill pill-warn">{{ $booking->booking_kind->label() }}</span>
                                </div>
                            @endif
                        </div>
                    </header>

                    @if ($cancelling === $booking->id)
                        {{-- Cancelling asks for a reason; the reasons come from QueueService. --}}
                        <div class="acts">
                            <span class="muted">{{ __('app.actions.cancel_prompt') }}</span>
                            @foreach ($cancelReasons as $reason)
                                <button type="button" class="btn btn-sm btn-danger"
                                        wire:click="cancel({{ $booking->id }}, '{{ $reason->value }}')">
                                    {{ $reason->label() }}
                                </button>
                            @endforeach
                            <button type="button" class="btn btn-sm" wire:click="dismissCancel">
                                {{ __('app.actions.dismiss') }}
                            </button>
                        </div>
                    @else
                        <div class="acts">
                            @if ($booking->status === BookingStatus::BOOKED)
                                <button type="button" class="btn btn-sm btn-primary"
                                        wire:click="arrive({{ $booking->id }})">
                                    {{ __('app.actions.arrive') }}
                                </button>
                            @elseif ($booking->status === BookingStatus::ARRIVED)
                                <button type="button" class="btn btn-sm btn-primary"
                                        wire:click="callIn({{ $booking->id }})">
                                    {{ __('app.actions.call_in') }}
                                </button>
                            @elseif ($booking->status === BookingStatus::WITH_DOCTOR)
                                <button type="button" class="btn btn-sm btn-primary"
                                        wire:click="complete({{ $booking->id }})">
                                    {{ __('app.actions.complete') }}
                                </button>
                            @endif

                            @if ($booking->status->canAdvanceTo(BookingStatus::NO_SHOW))
                                <button type="button" class="btn btn-sm"
                                        wire:click="noShow({{ $booking->id }})">
                                    {{ __('app.actions.no_show') }}
                                </button>
                            @endif

                            @if ($booking->canBeCancelled())
                                <button type="button" class="btn btn-sm btn-danger"
                                        wire:click="confirmCancel({{ $booking->id }})">
                                    {{ __('app.actions.cancel') }}
                                </button>
                            @endif

                            @if ($booking->patient?->phone)
                                <a class="btn btn-sm" href="tel:{{ $booking->patient->phone }}">
                                    {{ __('app.actions.call') }}
                                </a>
                            @endif

                            @if (! $booking->status->isTerminal())
                                <button type="button" class="btn btn-sm"
                                        onclick="navigator.clipboard.writeText('{{ $booking->trackingUrl() }}');
                                                 this.textContent='{{ __('app.actions.link_copied') }}'">
                                    {{ __('app.actions.copy_link') }}
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
