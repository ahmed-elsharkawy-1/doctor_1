@php
    use App\Enums\BookingKind;
    use App\Enums\BookingStatus;

    /** Wall-clock times with Arabic meridiems, without pulling in a formatter. */
    $clock = function ($time) {
        if ($time === null) {
            return null;
        }

        $meridiem = $time->format('A') === 'AM'
            ? __('booking.tracking.am')
            : __('booking.tracking.pm');

        return $time->format('g:i').' '.$meridiem;
    };

    $status = $booking->status;
    $isWaiting = $position !== null && ! $position->isNext();
    $isNext = $position !== null && $position->isNext();
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @if ($isWaiting || $isNext)
        <meta http-equiv="refresh" content="{{ $refreshSeconds }}">
    @endif
    <title>{{ __('booking.tracking.title', ['clinic' => $clinic->name]) }}</title>
    <style>
        :root {
            --bg: #eef2f7;
            --card: #ffffff;
            --ink: #16202e;
            --muted: #6b7a8d;
            --line: #e2e8f0;
            --brand: #0f766e;
            --brand-soft: #e6f4f1;
            --warn: #b45309;
            --warn-soft: #fef6e7;
            --danger: #b42318;
            --danger-soft: #fdeceb;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, system-ui, sans-serif;
            line-height: 1.6;
            -webkit-text-size-adjust: 100%;
        }

        .wrap {
            max-width: 30rem;
            margin: 0 auto;
            padding: 1rem 1rem 3rem;
        }

        .clinic {
            text-align: center;
            padding: 1.25rem 0 1rem;
        }

        .clinic h1 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 1.25rem;
            margin-bottom: 0.85rem;
        }

        /* The headline number, and the full-card states that replace it. */
        .headline {
            text-align: center;
            padding: 1.75rem 1.25rem;
        }

        .headline .count {
            font-size: 4rem;
            font-weight: 800;
            line-height: 1;
            color: var(--brand);
        }

        .headline .label {
            color: var(--muted);
            font-size: 0.95rem;
            margin-top: 0.4rem;
        }

        .headline.state-turn { background: var(--brand-soft); border-color: var(--brand); }
        .headline.state-done { background: var(--brand-soft); border-color: var(--brand); }
        .headline.state-off  { background: var(--danger-soft); border-color: var(--danger); }

        .headline .big {
            font-size: 1.9rem;
            font-weight: 800;
            color: var(--brand);
        }

        .headline.state-off .big { color: var(--danger); }

        .headline .note {
            color: var(--muted);
            margin-top: 0.35rem;
        }

        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
            margin-bottom: 0.85rem;
        }

        .split .card { margin-bottom: 0; text-align: center; }

        .split .n { font-size: 1.75rem; font-weight: 700; }
        .split .k { color: var(--muted); font-size: 0.85rem; }

        .notice {
            background: var(--warn-soft);
            border: 1px solid #f5d9a8;
            color: var(--warn);
            border-radius: 0.85rem;
            padding: 0.85rem 1rem;
            font-size: 0.9rem;
            margin-bottom: 0.85rem;
        }

        .rows { padding: 0.35rem 1.25rem; }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--line);
        }

        .row:last-child { border-bottom: 0; }
        .row .k { color: var(--muted); }
        .row .v { font-weight: 600; text-align: left; }

        .pill {
            display: inline-block;
            background: var(--brand-soft);
            color: var(--brand);
            border-radius: 999px;
            padding: 0.15rem 0.7rem;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .pill.off { background: var(--danger-soft); color: var(--danger); }

        .call {
            display: block;
            text-align: center;
            background: var(--brand);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            padding: 0.95rem;
            border-radius: 0.85rem;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1620;
                --card: #18222f;
                --ink: #e8eef5;
                --muted: #93a3b6;
                --line: #26333f;
                --brand: #4db6a5;
                --brand-soft: #16302c;
                --warn: #e0a458;
                --warn-soft: #2e2517;
                --danger: #e2857c;
                --danger-soft: #33201e;
            }

            .notice { border-color: #4a3c22; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="clinic">
        <h1>{{ $clinic->name }}</h1>
    </div>

    {{-- One of five states. Only a live booking on its own day gets a count. --}}
    @if ($status === BookingStatus::DONE)
        <div class="card headline state-done">
            <div class="big">{{ __('booking.tracking.done') }}</div>
            <div class="note">{{ __('booking.tracking.done_note') }}</div>
        </div>
    @elseif ($status === BookingStatus::CANCELLED)
        <div class="card headline state-off">
            <div class="big">{{ __('booking.tracking.cancelled') }}</div>
            <div class="note">{{ __('booking.tracking.cancelled_note') }}</div>
        </div>
    @elseif ($status === BookingStatus::NO_SHOW)
        <div class="card headline state-off">
            <div class="big">{{ __('booking.tracking.no_show') }}</div>
            <div class="note">{{ __('booking.tracking.no_show_note') }}</div>
        </div>
    @elseif ($isNext)
        <div class="card headline state-turn">
            <div class="big">{{ __('booking.tracking.your_turn') }}</div>
            <div class="note">{{ __('booking.tracking.your_turn_note') }}</div>
        </div>
    @elseif ($isWaiting)
        <div class="card headline">
            <div class="count">{{ $position->ahead }}</div>
            <div class="label">{{ __('booking.tracking.waiting_count') }}</div>
        </div>

        <div class="split">
            <div class="card">
                <div class="n">{{ $position->total }}</div>
                <div class="k">{{ __('booking.tracking.total') }}</div>
            </div>
            <div class="card">
                <div class="n">{{ $position->normal }}</div>
                <div class="k">{{ __('booking.tracking.normal') }}</div>
            </div>
        </div>

        <div class="notice">{{ __('booking.tracking.emergency_notice') }}</div>
    @else
        {{-- Booked, but not today: the count would be meaningless. --}}
        <div class="card headline">
            <div class="big">{{ __('booking.tracking.not_today') }}</div>
            <div class="note">{{ __('booking.tracking.not_today_note') }}</div>
        </div>
    @endif

    <div class="card rows">
        <div class="row">
            <span class="k">{{ __('booking.tracking.your_status') }}</span>
            <span class="v">
                <span class="pill @if ($status->isTerminal() && $status !== BookingStatus::DONE) off @endif">
                    {{ $status->label() }}
                </span>
            </span>
        </div>

        @if ($position?->expectedAt !== null)
            <div class="row">
                <span class="k">{{ __('booking.tracking.expected_at') }}</span>
                <span class="v">{{ $clock($position->expectedAt) }}</span>
            </div>
        @endif

        <div class="row">
            <span class="k">{{ __('booking.tracking.appointment') }}</span>
            <span class="v">
                {{ $booking->visit_date->format('Y-m-d') }}
                @if ($booking->start_at !== null)
                    — {{ $clock($booking->start_at) }}
                @endif
            </span>
        </div>

        @if ($booking->booking_kind === BookingKind::EMERGENCY)
            <div class="row">
                <span class="k">{{ __('booking.tracking.emergency') }}</span>
                <span class="v"><span class="pill off">{{ $booking->booking_kind->label() }}</span></span>
            </div>
        @endif

        @if ($booking->patient !== null)
            <div class="row">
                <span class="k">{{ __('booking.tracking.patient_code') }}</span>
                <span class="v">{{ $booking->patient->code }}</span>
            </div>
            <div class="row">
                <span class="k">{{ $booking->patient->name }}</span>
                <span class="v">{{ $booking->visitType?->name }}</span>
            </div>
        @endif
    </div>

    @if ($clinic->phone)
        <a class="call" href="tel:{{ $clinic->phone }}">{{ __('booking.tracking.call_clinic') }}</a>
    @endif

</div>
</body>
</html>
