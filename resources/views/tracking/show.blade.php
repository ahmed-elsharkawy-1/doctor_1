@php
    use App\Enums\BookingKind;
    use App\Enums\BookingStatus;

    /** Wall-clock times with Arabic meridiems. */
    $clock = function ($time): ?string {
        if ($time === null) {
            return null;
        }

        $meridiem = $time->format('A') === 'AM'
            ? __('booking.tracking.am')
            : __('booking.tracking.pm');

        return $time->format('g:i').' '.$meridiem;
    };

    $status = $booking->status;
    $isNext = $position !== null && $position->isNext();
    // Nobody booked before them, but they are not in the clinic yet.
    $isFirstInLine = $position !== null && $position->isFirstInLine();
    $isWaiting = $position !== null && ! $isNext && ! $isFirstInLine;
    $isDone = $status === BookingStatus::DONE;
    $isOff = $status->isTerminal() && ! $isDone;

    // How far the patient has come, as a fraction of today's queue. The arc
    // only advances as people ahead are seen; an emergency landing raises the
    // total, which the page warns about in words.
    $progress = $position === null || $position->total === 0
        ? 0.0
        : ($position->total - $position->ahead) / $position->total;

    if ($isDone) {
        $progress = 1.0;
    }

    $radius = 78;
    $circumference = 2 * M_PI * $radius;
    $dash = $circumference * min(1, max(0, $progress));

    $doctorName = $doctor?->name ?? $clinic->name;

    // An emergency holds no slot, so it has no time to show.
    $isEmergency = $booking->booking_kind === BookingKind::EMERGENCY;
    $timeLabel = $isEmergency
        ? __('booking.kind.emergency')
        : trim(($clock($booking->start_at) ?? '').' - '.($clock($booking->end_at) ?? ''), ' -');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @include('partials.brand-head')
    @if ($isWaiting || $isNext || $isFirstInLine)
        <meta http-equiv="refresh" content="{{ $refreshSeconds }}">
    @endif
    <title>{{ __('booking.tracking.title', ['clinic' => $clinic->name]) }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    <style>
        /* Same tokens as the doctor page (Public/*). Light only in V1. */
        :root {
            color-scheme: light;
            --ink: #132433;
            --muted: #5F6F80;
            --faint: #8B9AAA;
            --primary: #185FA5;
            --primary-50: #EEF4FB;
            --success: #1B9E57;
            --success-bg: #E7F4EC;
            --danger: #C0392B;
            --danger-bg: #FBECEA;
            --surface: #FFFFFF;
            --surface-2: #F6F9FC;
            --line: #E5ECF3;
            --bg: #EEF2F7;
            --shadow-sm: 0 4px 8px rgba(20, 60, 100, .06);
            --radius: 16px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: Tajawal, "Segoe UI", Tahoma, system-ui, sans-serif;
            font-size: 15px;
            line-height: 1.6;
            -webkit-text-size-adjust: 100%;
        }

        h1, h2, p { margin: 0; }
        a { color: inherit; text-decoration: none; }

        /*
           The first card is pulled up 22px to sit over this strip, which is a
           deliberate layered look — but at 54px that overlap was landing on
           the logo. The bar is taller now and reserves those 22px as padding,
           so the brand centres in the part that stays visible rather than in
           the part the card covers.
        */
        .topbar {
            /* The same two layers the doctor page's banner is built from, so
               the three pages a patient sees share one sky rather than three
               shades of blue. Literal values, matching landing/show. */
            background:
                radial-gradient(120% 140% at 85% 0%, #2C7FD0 0%, transparent 55%),
                linear-gradient(200deg, #1B6BB5 0%, #124C86 55%, #0E3E6E 100%);
            background-color: #124C86;
            height: 76px;
            padding-bottom: 22px;
            display: flex;
            align-items: center;
        }

        /* Lined up with the cards below rather than the window edge. */
        .topbar-inner {
            width: min(30rem, 100% - 24px);
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            font-weight: 800;
            font-size: 15px;
        }

        .topbar-inner img { height: 22px; width: auto; display: block; }
        .wrap { width: min(30rem, 100% - 24px); margin: 0 auto; padding-bottom: 32px; }

        .card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 16px;
            margin-bottom: 12px;
        }

        /* ---------- doctor ---------- */
        .doctor { margin-top: -22px; }

        .doctor .who { display: flex; align-items: center; gap: 12px; }
        .doctor img, .doctor .initial {
            width: 52px; height: 52px;
            flex: 0 0 auto;
            border-radius: 14px;
            object-fit: cover;
            background: var(--primary-50);
        }

        .doctor .initial {
            display: grid;
            place-items: center;
            color: var(--primary);
            font-size: 22px;
            font-weight: 800;
        }

        .doctor h1 { font-size: 17px; font-weight: 800; }
        .doctor .role { color: var(--muted); font-size: 13px; }

        .address {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--line);
            color: var(--primary);
            font-size: 13px;
        }

        .address .grow { flex: 1 1 auto; min-width: 0; text-decoration: underline; }

        /* ---------- ring ---------- */
        .stage { text-align: center; padding: 22px 16px; }

        .stage-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 16px;
        }

        .ring { position: relative; width: 176px; height: 176px; margin: 0 auto; }
        .ring svg { transform: rotate(-90deg); }
        .ring .track { stroke: var(--primary-50); }
        .ring .arc { stroke: var(--primary); transition: stroke-dasharray .6s ease; }
        .ring.is-done .arc { stroke: var(--success); }

        .ring .inner {
            position: absolute;
            inset: 0;
            display: grid;
            place-content: center;
            gap: 2px;
        }

        .ring .count { font-size: 44px; font-weight: 800; line-height: 1; color: var(--primary); }
        .ring .label { color: var(--muted); font-size: 13px; }
        .ring .word { font-size: 20px; font-weight: 800; color: var(--primary); line-height: 1.3; }
        .ring.is-done .word { color: var(--success); }
        .ring.is-off .arc { stroke: var(--danger); }
        .ring.is-off .word { color: var(--danger); }

        /* ---------- tiles ---------- */
        .tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px; }

        .tile {
            background: var(--surface);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            padding: 10px 6px;
            text-align: center;
        }

        .tile .n { font-size: 20px; font-weight: 800; }
        .tile .k { color: var(--muted); font-size: 12px; }
        .tile.warn .n { color: var(--danger); }

        .notice {
            display: flex;
            gap: 8px;
            background: var(--danger-bg);
            color: #8E2F23;
            border-radius: 12px;
            padding: 11px 13px;
            font-size: 12.5px;
            margin-bottom: 12px;
        }

        .notice svg { flex: 0 0 auto; margin-top: 2px; }

        /* ---------- two-up boxes ---------- */
        .duo { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }

        .box {
            background: var(--surface);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            padding: 11px 12px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .box .k { color: var(--muted); font-size: 12px; }
        .box .v { font-weight: 700; font-size: 14px; }
        .box svg { flex: 0 0 auto; color: var(--primary); }

        .call {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--surface);
            border: 1px solid var(--primary);
            color: var(--primary);
            border-radius: 12px;
            padding: 13px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        /* ---------- detail rows ---------- */
        .rows { padding: 4px 16px; }

        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid var(--line);
        }

        .row:last-child { border-bottom: 0; }
        .row .k { display: flex; align-items: center; gap: 7px; color: var(--muted); font-size: 13px; }
        .row .k svg { color: var(--faint); }
        .row .v { font-weight: 700; font-size: 14px; text-align: start; }

        .chip {
            display: inline-block;
            border-radius: 8px;
            background: var(--primary-50);
            color: var(--primary);
            padding: 3px 10px;
            font-size: 12.5px;
            font-weight: 700;
        }

        .chip.off { background: var(--danger-bg); color: var(--danger); }
        .code { color: var(--muted); font-weight: 700; font-size: 13px; direction: ltr; }
    </style>
</head>
<body>

@include('partials.brand-bar')

<div class="wrap">

    {{-- Who the patient is seeing --}}
    <section class="card doctor">
        <div class="who">
            @if ($doctor?->avatarUrl())
                <img src="{{ $doctor->avatarUrl() }}" alt="{{ $doctorName }}" loading="lazy">
            @else
                <div class="initial" aria-hidden="true">{{ mb_substr(preg_replace('/^د\.\s*/u', '', $doctorName), 0, 1) }}</div>
            @endif

            <div>
                <h1>{{ $doctorName }}</h1>
                @if ($doctor?->title || $clinic->specialty)
                    <p class="role">{{ $doctor?->title ?: $clinic->specialty?->name }}</p>
                @endif
            </div>
        </div>

        @if ($clinic->address)
            <a class="address" @if ($mapLink) href="{{ $mapLink }}" target="_blank" rel="noopener" @endif>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <span class="grow">{{ $clinic->address }}</span>
                @if ($mapLink)
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                @endif
            </a>
        @endif
    </section>

    {{-- The headline: a ring that fills as the patient moves up --}}
    <section class="card stage">
        {{-- A ring with a number in it does not say what the number counts.
             The heading does, and only while the ring is about the queue: a
             finished or cancelled visit has no position to report. --}}
        @if ($isWaiting || $isNext || $isFirstInLine)
            <h2 class="stage-title">{{ __('booking.tracking.queue_title') }}</h2>
        @endif

        <div class="ring @if ($isDone) is-done @endif @if ($isOff) is-off @endif">
            <svg width="176" height="176" viewBox="0 0 176 176">
                <circle class="track" cx="88" cy="88" r="{{ $radius }}" fill="none" stroke-width="12"/>
                <circle class="arc" cx="88" cy="88" r="{{ $radius }}" fill="none" stroke-width="12"
                        stroke-linecap="round"
                        stroke-dasharray="{{ round($dash, 2) }} {{ round($circumference, 2) }}"/>
            </svg>

            <div class="inner">
                @if ($isDone)
                    <span class="word">{{ __('booking.tracking.done') }}</span>
                @elseif ($status === BookingStatus::CANCELLED)
                    <span class="word">{{ __('booking.tracking.cancelled') }}</span>
                @elseif ($status === BookingStatus::NO_SHOW)
                    <span class="word">{{ __('booking.tracking.no_show') }}</span>
                @elseif ($isNext)
                    <span class="word">{{ __('booking.tracking.your_turn') }}</span>
                @elseif ($isFirstInLine)
                    <span class="word">{{ __('booking.tracking.first_in_line') }}</span>
                @elseif ($isWaiting)
                    <span class="count">{{ $position->ahead }}</span>
                    <span class="label">{{ __('booking.tracking.waiting_count') }}</span>
                @else
                    <span class="word">{{ __('booking.tracking.not_today') }}</span>
                @endif
            </div>
        </div>
    </section>

    @if ($isFirstInLine)
        {{-- Nobody before them, but they are not here yet: say when to come
             rather than calling them into the examination room. --}}
        <div class="notice">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            @if ($booking->start_at)
                {{ __('booking.tracking.first_in_line_note', [
                    'time' => $clock($booking->start_at),
                    'lead' => __('messages.minutes', ['count' => (int) $clinic->patient_arrival_lead_minutes]),
                ]) }}
            @else
                {{ __('booking.tracking.first_in_line_note_no_time') }}
            @endif
        </div>
    @endif

    @if ($isWaiting)
        <div class="tiles">
            <div class="tile">
                <div class="n">{{ $position->total }}</div>
                <div class="k">{{ __('booking.tracking.total') }}</div>
            </div>
            <div class="tile">
                <div class="n">{{ $position->normal }}</div>
                <div class="k">{{ __('booking.tracking.normal') }}</div>
            </div>
            <div class="tile warn">
                <div class="n">{{ $position->emergency }}</div>
                <div class="k">{{ __('booking.tracking.emergency') }}</div>
            </div>
        </div>

        <div class="notice">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.4 17a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            {{ __('booking.tracking.emergency_notice') }}
        </div>

        <div class="duo">
            @if ($position->expectedAt)
                <div class="box">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <span>
                        <span class="k">{{ __('booking.tracking.expected_at') }}</span><br>
                        <span class="v">{{ $clock($position->expectedAt) }}</span>
                    </span>
                </div>
            @endif

            <div class="box">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11h5M19.5 8.5v5"/></svg>
                <span>
                    <span class="k">{{ __('booking.tracking.your_status') }}</span><br>
                    <span class="v">{{ $status->label() }}</span>
                </span>
            </div>
        </div>
    @endif

    @if ($phone)
        <a class="call" href="tel:{{ $phone }}">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.9v2a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 3.2 2 2 0 0 1 4.1 1h2a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L7.1 8.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg>
            {{ __('booking.tracking.call_clinic') }}
        </a>
    @endif

    {{-- The booking itself --}}
    <section class="card rows">
        <div class="row">
            <span class="k">{{ __('booking.tracking.patient_code') }}</span>
            <span class="code">#{{ $booking->patient?->code }}</span>
        </div>

        <div class="row">
            <span class="k">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                {{ __('booking.tracking.patient_name') }}
            </span>
            <span class="v">{{ $booking->patient?->name }}</span>
        </div>

        <div class="row">
            <span class="k">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                {{ __('booking.tracking.time') }}
            </span>
            <span class="v">{{ $timeLabel }}</span>
        </div>

        <div class="row">
            <span class="k">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>
                {{ __('booking.tracking.date') }}
            </span>
            <span class="v">{{ $booking->visit_date->translatedFormat('l، j/n/Y') }}</span>
        </div>

        <div class="row">
            <span class="k">{{ __('booking.tracking.booking_type') }}</span>
            <span class="v">
                <span class="chip @if ($isEmergency) off @endif">
                    {{ $isEmergency ? __('booking.kind.emergency') : ($booking->visitType?->name ?? __('booking.kind.normal')) }}
                </span>
            </span>
        </div>
    </section>

</div>
</body>
</html>
