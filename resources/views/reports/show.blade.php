@php
    $period = $report->period;
    $money = fn (float $amount) => number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2).' '.__('messages.currency');
    $from = $period->from->copy()->locale(app()->getLocale());
    $hasBookings = $report->outcomes['total'] > 0;
    $dayStatus = $report->dayStatus;
    $label = fn ($date) => $date->copy()->locale(app()->getLocale());
    $previousFrom = \Illuminate\Support\Carbon::parse($report->completed['previous_from']);
    $previousTo = \Illuminate\Support\Carbon::parse($report->completed['previous_to']);
    $previousLabel = $type === 'day'
        ? $label($previousFrom)->isoFormat('dddd D MMMM')
        : $label($previousFrom)->isoFormat('D MMMM').' – '.$label($previousTo)->isoFormat('D MMMM');
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
        .rp-label { font-weight: 700; font-size: .85rem; color: var(--muted); margin: 0 0 6px; }
        /* The period picker: a native select, so the phone's own picker opens
           (a wheel on iPhone, a sheet on Android) with every option in reach. */
        .rp-picker { position: relative; margin-top: 12px; }
        .rp-picker select {
            width: 100%; appearance: none; -webkit-appearance: none;
            padding: 12px 14px 12px 40px; border: 1px solid var(--line); border-radius: 12px;
            background: var(--surface-2); color: var(--ink); font: inherit; font-weight: 700;
        }
        .rp-picker::after {
            content: ""; position: absolute; left: 16px; top: 50%; width: 8px; height: 8px;
            border-left: 2px solid var(--muted); border-bottom: 2px solid var(--muted);
            transform: translateY(-70%) rotate(-45deg); pointer-events: none;
        }
        .rp-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
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
        .rp-dayrow.is-quiet td, .rp-weekrow.is-quiet td { color: var(--muted); }
        /* Two tables read as one: the same fixed column widths. */
        .rp-fixed { table-layout: fixed; }
        .rp-fixed th:nth-child(1), .rp-fixed td:nth-child(1) { width: 42%; }
        .rp-fixed th:nth-child(2), .rp-fixed td:nth-child(2) { width: 33%; }
        .rp-more summary {
            list-style: none; cursor: pointer; text-align: center; padding: 10px;
            margin: 8px 0; border-radius: 12px; background: var(--surface-2);
            color: var(--primary); font-weight: 700;
        }
        .rp-more summary::-webkit-details-marker { display: none; }
        .rp-more .rp-more-close, .rp-more[open] .rp-more-open { display: none; }
        .rp-more[open] .rp-more-close { display: inline; }
        .rp-notice { display: flex; flex-direction: column; gap: 4px; background: var(--primary-50); }
        .rp-compare { margin: 12px 0 0; font-size: .88rem; color: var(--muted); }
        .rp-change { display: inline-block; font-weight: 800; margin-inline-end: 6px; }
        .rp-change-up { color: var(--success); }
        .rp-change-down { color: var(--danger); }
    </style>

    <section class="rp-card">
        <div class="rp-head">
            <div>
                <h1>{{ $clinic->name }}</h1>
                {{-- The dropdown already names a day; a week or month also gets its dates. --}}
                @if ($type !== 'day')
                    <p class="rp-muted" style="margin: 4px 0 0">{{ $heading }}</p>
                @endif
            </div>
            @unless ($preview)
                <form method="POST" action="{{ route('reports.logout') }}">
                    @csrf
                    <button class="rp-btn rp-btn-quiet" type="submit">{{ __('reports.page.sign_out') }}</button>
                </form>
            @endunless
        </div>

        {{-- Every period in one list, grouped; picking one opens it. One
             control, so it always names exactly what the numbers below are for. --}}
        <div class="rp-picker">
            <label class="rp-sr" for="rp-period">{{ __('reports.page.period_label') }}</label>
            <select id="rp-period" onchange="if (this.value) { window.location.href = this.value; }">
                <optgroup label="{{ __('reports.page.group_days') }}">
                    @foreach ($days as $day)
                        <option value="{{ $link('day', $day['value']) }}" @selected($type === 'day' && $value === $day['value'])>{{ $loop->first ? __('reports.page.yesterday').' — ' : '' }}{{ $label(\Illuminate\Support\Carbon::parse($day['value']))->isoFormat('dddd D MMMM') }}</option>
                    @endforeach
                </optgroup>
                @foreach (['week' => 'group_weeks', 'month' => 'group_months'] as $group => $title)
                    <optgroup label="{{ __('reports.page.'.$title) }}">
                        @foreach ($periods as $option)
                            @continue($option['type'] !== $group)
                            <option value="{{ $link($option['type'], $option['value']) }}" @selected($type === $option['type'] && $value === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
    </section>


    {{--
        The doctor's order: how it went, what is next, what went wrong, then
        the detail. A section with nothing to say is left out rather than
        drawn empty.
    --}}
    @if ($dayStatus && $dayStatus['kind'] !== 'open')
        <section class="rp-card rp-notice">
            <b>{{ $dayStatus['kind'] === 'holiday' ? __('reports.page.was_holiday') : __('reports.page.was_closed') }}</b>
            @if ($dayStatus['note'])<span class="rp-muted">{{ $dayStatus['note'] }}</span>@endif
        </section>
    @endif

    @if ($hasBookings)
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

            @if ($report->completed['previous_count'] > 0)
                <p class="rp-compare">
                    @if ($report->completed['change_percent'] !== null && $report->completed['direction'] !== 'flat')
                        <span class="rp-change rp-change-{{ $report->completed['direction'] }}">{{ __('reports.page.change_'.$report->completed['direction'], ['percent' => abs($report->completed['change_percent'])]) }}</span>
                    @endif
                    {{ __('reports.page.compared_with', [
                        'period' => $previousLabel,
                        'count' => trans_choice('reports.page.visits', $report->completed['previous_count'], ['count' => $report->completed['previous_count']]),
                        'income' => $money($report->completed['previous_income']),
                    ]) }}
                </p>
            @endif
        </section>
    @elseif (! $dayStatus || $dayStatus['kind'] === 'open')
        <section class="rp-card">
            <p class="rp-muted" style="margin: 0">{{ __('reports.page.no_bookings') }}</p>
        </section>
    @endif

    @if ($report->nextDayBookings !== null)
        <section class="rp-card">
            <div class="rp-stat">
                <span class="rp-muted">{{ __('reports.page.next_day') }}</span>
                <b>{{ $report->nextDayBookings }}</b>
            </div>
        </section>
    @endif

    @if ($hasBookings)
        <section class="rp-card">
            <h2>{{ __('reports.page.outcomes') }}</h2>
            <div class="rp-outcomes">
                <div class="rp-stat rp-done"><b>{{ $report->outcomes['done'] }}</b><span class="rp-muted">{{ __('reports.page.done') }}</span></div>
                <div class="rp-stat rp-bad"><b>{{ $report->outcomes['no_show'] }}</b><span class="rp-muted">{{ __('reports.page.no_show') }}</span></div>
                <div class="rp-stat rp-bad"><b>{{ $report->outcomes['cancelled'] }}</b><span class="rp-muted">{{ __('reports.page.cancelled') }}</span></div>
            </div>
            @if ($report->outcomes['no_show'] > 0 && $report->outcomes['no_show_rate'] !== null)
                <p class="rp-muted" style="margin: 10px 0 0">
                    {{ __('reports.page.no_show_rate', [
                        'no_show' => $report->outcomes['no_show'],
                        'expected' => $report->outcomes['done'] + $report->outcomes['no_show'],
                        'percent' => $report->outcomes['no_show_rate'],
                    ]) }}
                </p>
            @endif
        </section>
    @endif

    @if ($report->completed['count'] > 0)
        <section class="rp-card">
            <h2>{{ __('reports.page.by_type') }}</h2>
            <table class="rp-table">
                <thead><tr><th>{{ __('reports.page.type') }}</th><th>{{ __('reports.page.count') }}</th><th>{{ __('reports.page.income') }}</th></tr></thead>
                <tbody>
                    @foreach ($report->byVisitType as $row)
                        <tr><td>{{ $row['name'] }}</td><td>{{ $row['count'] }}</td><td><bdi>{{ $money($row['income']) }}</bdi></td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="rp-card">
            <h2>{{ __('reports.page.patients') }}</h2>
            <div class="rp-stats">
                <div class="rp-stat"><span class="rp-muted">{{ __('reports.page.new') }}</span><b>{{ $report->patients['new_count'] }}</b></div>
                <div class="rp-stat"><span class="rp-muted">{{ __('reports.page.returning') }}</span><b>{{ $report->patients['returning_count'] }}</b></div>
            </div>

            @if ($report->patients['new'] !== [])
                @php
                    // Five on the page; the rest expand in place — a pop-up's
                    // back button would leave the report, not close the list.
                    $firstPatients = array_slice($report->patients['new'], 0, 5);
                    $morePatients = array_slice($report->patients['new'], 5);
                @endphp
                <p class="rp-label" style="margin-top: 12px">{{ __('reports.page.new_patients') }}</p>
                <table class="rp-table rp-fixed">
                    <thead><tr><th>{{ __('reports.page.name') }}</th><th>{{ __('reports.page.phone') }}</th><th>{{ __('reports.page.type') }}</th></tr></thead>
                    <tbody>
                        @foreach ($firstPatients as $patient)
                            @include('reports.partials.patient-row', ['patient' => $patient])
                        @endforeach
                    </tbody>
                </table>
                @if ($morePatients !== [])
                    <details class="rp-more">
                        <summary>
                            <span class="rp-more-open">{{ __('reports.page.show_all', ['count' => count($report->patients['new'])]) }}</span>
                            <span class="rp-more-close">{{ __('reports.page.show_less') }}</span>
                        </summary>
                        <table class="rp-table rp-fixed">
                            <tbody>
                                @foreach ($morePatients as $patient)
                                    @include('reports.partials.patient-row', ['patient' => $patient])
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                @endif
            @endif
        </section>

        @if ($type === 'week' && $report->daily !== [])
            <section class="rp-card">
                <h2>{{ __('reports.page.by_day') }}</h2>
                <table class="rp-table">
                    <thead><tr><th>{{ __('reports.page.date') }}</th><th>{{ __('reports.page.count') }}</th><th>{{ __('reports.page.income') }}</th></tr></thead>
                    <tbody>
                        @foreach ($report->daily as $day)
                            <tr class="rp-dayrow @if ($day['kind'] !== 'open' || $day['count'] === 0) is-quiet @endif">
                                <td>{{ $label(\Illuminate\Support\Carbon::parse($day['date']))->isoFormat('ddd D/M') }}</td>
                                @if ($day['kind'] === 'holiday')
                                    <td colspan="2">{{ __('reports.page.row_holiday') }}@if ($day['note']) — {{ $day['note'] }}@endif</td>
                                @elseif ($day['kind'] === 'closed')
                                    <td colspan="2">{{ __('reports.page.row_closed') }}</td>
                                @elseif ($day['count'] === 0)
                                    <td colspan="2">{{ __('reports.page.row_none') }}</td>
                                @else
                                    <td>{{ $day['count'] }}</td>
                                    <td><bdi>{{ $money($day['income']) }}</bdi></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        @if ($type === 'month' && $report->weekly !== [])
            <section class="rp-card">
                <h2>{{ __('reports.page.by_week') }}</h2>
                <table class="rp-table">
                    <thead><tr><th>{{ __('reports.page.week') }}</th><th>{{ __('reports.page.count') }}</th><th>{{ __('reports.page.income') }}</th></tr></thead>
                    <tbody>
                        @foreach ($report->weekly as $week)
                            <tr class="rp-weekrow @if ($week['count'] === 0) is-quiet @endif">
                                <td>{{ $label(\Illuminate\Support\Carbon::parse($week['from']))->isoFormat('D/M') }} – {{ $label(\Illuminate\Support\Carbon::parse($week['to']))->isoFormat('D/M') }}</td>
                                @if ($week['count'] === 0)
                                    <td colspan="2">{{ __('reports.page.row_none') }}</td>
                                @else
                                    <td>{{ $week['count'] }}</td>
                                    <td><bdi>{{ $money($week['income']) }}</bdi></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    @endif

</x-layouts.reports>
