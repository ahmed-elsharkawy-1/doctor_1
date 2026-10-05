@php
    $period = $report->period;
    $money = fn (float $amount) => number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2).' '.__('messages.currency');
    $from = $period->from->copy()->locale(app()->getLocale());
    $heading = match ($type) {
        'day' => $from->isoFormat('dddd D MMMM YYYY'),
        'week' => __('reports.message.week', ['from' => $from->isoFormat('D MMMM'), 'to' => $period->to->copy()->locale(app()->getLocale())->isoFormat('D MMMM')]),
        'month' => $from->isoFormat('MMMM YYYY'),
    };
@endphp

<x-layouts.reports :title="__('reports.page.title').' — '.$clinic->name">
    <style>
        .rp-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .rp-head h1 { font-size: 1.15rem; margin: 0; }
        /* The two filter strips scroll sideways. As on the doctor page's tabs:
           no scrollbar line, a soft fade on whichever side still has chips,
           and the chosen one centred when the page opens (script below). */
        .rp-strip { position: relative; margin-bottom: 10px; --fade: 32px; }
        .rp-strip::before,
        .rp-strip::after {
            content: ""; position: absolute; top: 0; bottom: 0; width: var(--fade);
            pointer-events: none; opacity: 0; transition: opacity .2s ease; z-index: 1;
        }
        .rp-strip::before { right: 0; background: linear-gradient(to left, var(--surface), rgba(255, 255, 255, 0)); }
        .rp-strip::after { left: 0; background: linear-gradient(to right, var(--surface), rgba(255, 255, 255, 0)); }
        .rp-strip[data-scroll~="start"]::before { opacity: 1; }
        .rp-strip[data-scroll~="end"]::after { opacity: 1; }
        .rp-chips { display: flex; gap: 8px; overflow-x: auto; scrollbar-width: none; padding: 2px 0; }
        .rp-chips::-webkit-scrollbar { display: none; }

        /* A scroll bar of our own under each strip: the fade alone was too
           quiet to say "there is more". Drawn rather than styled because iOS
           ignores scroll bar CSS and shows its own only mid-swipe. The thumb's
           length is how much of the strip is on screen, its place is where
           the strip is; hidden when everything fits. */
        .rp-track { position: relative; height: 4px; margin-top: 8px; border-radius: 999px; background: var(--line); }
        .rp-track[hidden] { display: none; }
        .rp-thumb { position: absolute; top: 0; bottom: 0; right: 0; border-radius: 999px; background: var(--primary); opacity: .55; }
        .rp-chip {
            flex: none; padding: 8px 12px; border-radius: 999px; text-decoration: none;
            background: var(--surface-2); color: var(--ink); border: 1px solid var(--line); font-size: .88rem; white-space: nowrap;
        }
        .rp-chip.is-active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .rp-label { font-weight: 700; font-size: .85rem; color: var(--muted); margin: 0 0 6px; }
        .rp-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .rp-stat { background: var(--surface-2); border-radius: 12px; padding: 12px; }
        .rp-stat b { display: block; font-size: 1.5rem; }
        .rp-outcomes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; text-align: center; }
        .rp-outcomes b { display: block; font-size: 1.25rem; }
        .rp-done b { color: var(--success); }
        .rp-bad b { color: var(--danger); }
        .rp-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .rp-table th, .rp-table td { text-align: start; padding: 8px 6px; border-bottom: 1px solid var(--line); }
        .rp-table th { color: var(--muted); font-weight: 700; }
    </style>

    <section class="rp-card">
        <div class="rp-head">
            <div>
                <h1>{{ $clinic->name }}</h1>
                <p class="rp-muted" style="margin: 4px 0 0">{{ $heading }}</p>
            </div>
            @unless ($preview)
                <form method="POST" action="{{ route('reports.logout') }}">
                    @csrf
                    <button class="rp-btn rp-btn-quiet" type="submit">{{ __('reports.page.sign_out') }}</button>
                </form>
            @endunless
        </div>
    </section>

    <nav class="rp-card" aria-label="{{ __('reports.page.days') }}">
        <p class="rp-label">{{ __('reports.page.days') }}</p>
        <div class="rp-strip"><div class="rp-chips">
            @foreach ($days as $day)
                @php $active = $type === 'day' && $value === $day['value']; @endphp
                <a class="rp-chip @if ($active) is-active @endif" @if ($active) aria-current="page" @endif
                   href="{{ $link('day', $day['value']) }}">{{ $day['label'] }}</a>
            @endforeach
        </div><div class="rp-track" aria-hidden="true" hidden><span class="rp-thumb"></span></div></div>

        <p class="rp-label">{{ __('reports.page.periods') }}</p>
        <div class="rp-strip"><div class="rp-chips">
            @foreach ($periods as $option)
                @php $active = $type === $option['type'] && $value === $option['value']; @endphp
                <a class="rp-chip @if ($active) is-active @endif" @if ($active) aria-current="page" @endif
                   href="{{ $link($option['type'], $option['value']) }}">{{ $option['label'] }}</a>
            @endforeach
        </div><div class="rp-track" aria-hidden="true" hidden><span class="rp-thumb"></span></div></div>
    </nav>

    <section class="rp-card">
        <div class="rp-stats">
            <div class="rp-stat">
                <span class="rp-muted">{{ __('reports.page.completed') }}</span>
                <b>{{ $report->completed['count'] }}</b>
            </div>
            <div class="rp-stat">
                <span class="rp-muted">{{ __('reports.page.income') }}</span>
                <b><bdi>{{ $money($report->completed['income']) }}</bdi></b>
            </div>
        </div>
        <p class="rp-muted" style="margin: 10px 0 0">
            {{ __('reports.page.previous', ['count' => $report->completed['previous_count'], 'income' => $money($report->completed['previous_income'])]) }}
        </p>
    </section>

    <section class="rp-card">
        <h2>{{ __('reports.page.by_type') }}</h2>
        @if ($report->byVisitType === [])
            <p class="rp-muted">{{ __('reports.page.empty') }}</p>
        @else
            <table class="rp-table">
                <thead><tr><th>{{ __('reports.page.type') }}</th><th>{{ __('reports.page.count') }}</th><th>{{ __('reports.page.income') }}</th></tr></thead>
                <tbody>
                    @foreach ($report->byVisitType as $row)
                        <tr><td>{{ $row['name'] }}</td><td>{{ $row['count'] }}</td><td><bdi>{{ $money($row['income']) }}</bdi></td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="rp-card">
        <h2>{{ __('reports.page.outcomes') }}</h2>
        <div class="rp-outcomes">
            <div class="rp-stat rp-done"><b>{{ $report->outcomes['done'] }}</b><span class="rp-muted">{{ __('reports.page.done') }}</span></div>
            <div class="rp-stat rp-bad"><b>{{ $report->outcomes['no_show'] }}</b><span class="rp-muted">{{ __('reports.page.no_show') }}</span></div>
            <div class="rp-stat rp-bad"><b>{{ $report->outcomes['cancelled'] }}</b><span class="rp-muted">{{ __('reports.page.cancelled') }}</span></div>
        </div>
    </section>

    <section class="rp-card">
        <h2>{{ __('reports.page.patients') }}</h2>
        <div class="rp-stats" style="margin-bottom: 12px">
            <div class="rp-stat"><span class="rp-muted">{{ __('reports.page.new') }}</span><b>{{ $report->patients['new_count'] }}</b></div>
            <div class="rp-stat"><span class="rp-muted">{{ __('reports.page.returning') }}</span><b>{{ $report->patients['returning_count'] }}</b></div>
        </div>

        <p class="rp-label">{{ __('reports.page.new_patients') }}</p>
        @if ($report->patients['new'] === [])
            <p class="rp-muted">{{ __('reports.page.no_new_patients') }}</p>
        @else
            <table class="rp-table">
                <thead><tr><th>{{ __('reports.page.name') }}</th><th>{{ __('reports.page.phone') }}</th><th>{{ __('reports.page.type') }}</th></tr></thead>
                <tbody>
                    @foreach ($report->patients['new'] as $patient)
                        <tr>
                            <td>{{ $patient['name'] }}</td>
                            <td>@if ($patient['phone'])<a href="tel:{{ $patient['phone'] }}"><bdi>{{ $patient['phone'] }}</bdi></a>@endif</td>
                            <td>{{ $patient['visit_type'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($report->nextDayBookings !== null)
        <section class="rp-card">
            <div class="rp-stat">
                <span class="rp-muted">{{ __('reports.page.next_day') }}</span>
                <b>{{ $report->nextDayBookings }}</b>
            </div>
        </section>
    @endif

    @if ($report->daily !== [])
        <section class="rp-card">
            <h2>{{ __('reports.page.by_day') }}</h2>
            <table class="rp-table">
                <thead><tr><th>{{ __('reports.page.date') }}</th><th>{{ __('reports.page.count') }}</th><th>{{ __('reports.page.income') }}</th></tr></thead>
                <tbody>
                    @foreach ($report->daily as $day)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($day['date'])->locale(app()->getLocale())->isoFormat('ddd D/M') }}</td>
                            <td>{{ $day['count'] }}</td>
                            <td><bdi>{{ $money($day['income']) }}</bdi></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <script>
        /*
           The chips are links, so every choice opens a new page — and a new
           page starts each strip at its beginning, leaving a chip from the far
           end half off the screen. So the chosen chip is centred on arrival,
           instantly: the strip opens where it belongs instead of sliding there.

           Same measurement as the doctor page's tabs (landing/show): only the
           strip scrolls, never the page, and scrollBy is relative, so it reads
           the same in RTL whatever a browser does with scrollLeft.
        */
        (function () {
            const strips = document.querySelectorAll('.rp-strip');

            function centre(row, chip) {
                const rowBox = row.getBoundingClientRect();
                const chipBox = chip.getBoundingClientRect();
                const delta = (chipBox.left + chipBox.width / 2) - (rowBox.left + rowBox.width / 2);

                // 'instant', not 'auto': a page-load jump should never animate.
                row.scrollBy({ left: delta, behavior: 'instant' });
            }

            // Which way each strip still has chips to give. In RTL scrollLeft
            // counts down from zero, so it is measured by magnitude, not sign.
            function markOverflow(wrap, row) {
                const offset = Math.abs(row.scrollLeft);
                const hidden = row.scrollWidth - row.clientWidth;
                const sides = [];

                if (hidden > 1) {
                    if (offset > 1) { sides.push('start'); }
                    if (offset < hidden - 1) { sides.push('end'); }
                }

                wrap.setAttribute('data-scroll', sides.join(' '));
                drawTrack(wrap, row, offset, hidden);
            }

            // The visible window is [offset, offset + clientWidth] of the whole
            // strip, measured from its start — the right edge, in RTL — so the
            // thumb is placed from the right by the same proportions.
            function drawTrack(wrap, row, offset, hidden) {
                const track = wrap.querySelector('.rp-track');
                if (!track) { return; }

                track.hidden = hidden <= 1;
                if (track.hidden) { return; }

                const thumb = track.querySelector('.rp-thumb');
                thumb.style.width = (row.clientWidth / row.scrollWidth * 100) + '%';
                thumb.style.right = (offset / row.scrollWidth * 100) + '%';
            }

            function settle() {
                strips.forEach(wrap => {
                    const row = wrap.querySelector('.rp-chips');
                    const chip = row.querySelector('[aria-current="page"]');

                    if (chip) { centre(row, chip); }
                    markOverflow(wrap, row);
                });
            }

            strips.forEach(wrap => {
                const row = wrap.querySelector('.rp-chips');
                row.addEventListener('scroll', () => markOverflow(wrap, row), { passive: true });
            });

            window.addEventListener('resize', settle);
            settle();
            // The chips change width once the web font arrives; centre again.
            document.fonts?.ready.then(settle);
        })();
    </script>
</x-layouts.reports>
