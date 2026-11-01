@php
    use App\Enums\BookingStatus;

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
        .tiles { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.85rem; }
        .tile { flex: 1 1 6rem; padding: 0.7rem; text-align: center; }
        .tile .n { font-size: 1.4rem; font-weight: 700; }
        .tile .k { color: var(--muted); font-size: 0.8rem; }
        .tile.warn .n { color: #b42318; }

        .visit {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.8rem 0;
            border-top: 1px solid var(--line);
        }

        .visit:first-of-type { border-top: 0; padding-top: 0; }
        .visit .meta { color: var(--muted); font-size: 0.85rem; }
        .visit.is-terminal { opacity: 0.65; }
        .money { font-weight: 700; white-space: nowrap; }

        .online { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; padding: 0.9rem 1rem; margin-bottom: 0.85rem; }
        .online .meta { color: var(--muted); font-size: 0.85rem; margin-top: 0.25rem; }
    </style>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    <div class="topbar">
        <div>
            <h1>{{ $patient->name }}</h1>
            <div class="who">
                {{ $patient->code }}
                @if ($patient->phone) · <span dir="ltr">{{ $patient->phone }}</span> @endif
                @if ($patient->age) · {{ $patient->age }} @endif
            </div>
        </div>

        <a class="btn btn-sm" href="{{ route('app.patients') }}">{{ __('app.patients.back') }}</a>
    </div>

    <div class="tiles">
        <div class="card tile">
            <div class="n">{{ $summary['visits_count'] }}</div>
            <div class="k">{{ __('app.patients.visits') }}</div>
        </div>
        <div class="card tile warn">
            <div class="n">{{ $summary['no_show_count'] }}</div>
            <div class="k">{{ __('booking.status.no_show') }}</div>
        </div>
        <div class="card tile warn">
            <div class="n">{{ $summary['cancelled_count'] }}</div>
            <div class="k">{{ __('booking.status.cancelled') }}</div>
        </div>
    </div>

    {{-- Whether they may book on the public page, and how close the no-show
         rule is to stopping them. --}}
    <div class="card online">
        <div>
            <div style="font-size:0.85rem;color:var(--muted)">{{ __('patient.online_booking.title') }}</div>
            <div style="margin-top:0.3rem">
                @if ($onlineBooking['allowed'])
                    <span class="pill">{{ __('patient.online_booking.allowed') }}</span>
                @else
                    <span class="pill pill-off">{{ __('patient.online_booking.blocked') }}</span>
                    <span class="meta">· {{ $onlineBooking['reason']['display'] ?? '' }}</span>
                @endif
            </div>
            <div class="meta">
                @if (! $onlineBooking['allowed'] && $onlineBooking['blocked_at'] !== null)
                    {{ __('patient.online_booking.blocked_since', ['date' => $patient->online_booking_blocked_at->format('Y-m-d')]) }} ·
                @endif
                @if ($onlineBooking['rule_enabled'])
                    {{ __('patient.online_booking.counted', [
                        'days' => $onlineBooking['window_days'],
                        'count' => $onlineBooking['counted_no_shows'],
                        'threshold' => $onlineBooking['threshold'],
                    ]) }}
                @else
                    {{ __('patient.online_booking.rule_off') }}
                @endif
            </div>
            <div class="meta">
                {{ __('patient.online_booking.online_bookings') }}: {{ $summary['online_bookings_count'] }}
                · {{ __('patient.online_booking.clinic_bookings') }}: {{ $summary['clinic_bookings_count'] }}
                @if ($summary['last_no_show'] !== null)
                    · {{ __('patient.online_booking.last_no_show') }}: {{ $summary['last_no_show']->visit_date->format('Y-m-d') }}
                @endif
            </div>
        </div>

        @if ($canSwitchOnlineBooking)
            @if ($onlineBooking['allowed'])
                <button type="button" class="btn btn-sm" wire:click="blockOnlineBooking"
                        wire:confirm="{{ __('patient.online_booking.block_confirm') }}">
                    {{ __('patient.online_booking.block') }}
                </button>
            @else
                <button type="button" class="btn btn-sm btn-primary" wire:click="allowOnlineBooking">
                    {{ __('patient.online_booking.allow') }}
                </button>
            @endif
        @endif
    </div>

    <div class="card" style="padding:1rem">
        <h2 style="font-size:0.85rem;color:var(--muted);margin:0 0 0.75rem">
            {{ __('app.patients.history') }}
        </h2>

        @if ($history->isEmpty())
            <p class="muted" style="margin:0">{{ __('app.patients.no_visits') }}</p>
        @else
            @foreach ($history as $booking)
                <div class="visit @if ($booking->status->isTerminal() && $booking->status !== BookingStatus::DONE) is-terminal @endif">
                    <span>
                        <span style="font-weight:600">{{ $booking->visit_date->format('Y-m-d') }}</span>
                        <span class="meta">
                            · {{ $clock($booking->start_at) }}
                            <x-after-midnight :show="$booking->startsAfterMidnight()" />
                            · {{ $booking->visitType?->name }}
                            {{-- The snapshot taken when it was booked, never
                                 the visit type's price today. --}}
                            · {{ $booking->duration_minutes }} {{ __('app.patients.minutes') }}
                        </span>
                    </span>

                    <span style="display:flex;gap:0.5rem;align-items:center">
                        @if ($canSeePrices)
                            <span class="money">{{ rtrim(rtrim(number_format((float) $booking->price, 2, '.', ','), '0'), '.') }} {{ __('messages.currency') }}</span>
                        @endif

                        <span class="pill @if ($booking->status->isTerminal() && $booking->status !== BookingStatus::DONE) pill-off @endif">
                            {{ $booking->status->label() }}
                        </span>
                    </span>
                </div>
            @endforeach
        @endif
    </div>
</div>
