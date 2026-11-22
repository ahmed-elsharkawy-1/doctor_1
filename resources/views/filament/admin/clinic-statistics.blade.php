@php
    /** @var \App\Services\Results\V1\Statistics\ClinicStatisticsResult|null $result */
    $pct = fn (?float $v) => $v === null ? null : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    $min = fn (?float $v) => $v === null ? '—' : __('statistics.minutes', ['value' => rtrim(rtrim(number_format($v, 1), '0'), '.')]);
    $money = fn (?float $v) => $v === null ? null : number_format($v, 2);
    $muted = 'font-size: 0.8rem; opacity: 0.7; margin: 0;';
    $big = 'font-size: 1.9rem; font-weight: 700; line-height: 1.2; margin: 0;';
    $placeholder = 'font-size: 1.1rem; font-weight: 600; opacity: 0.6; margin: 0;';
@endphp

<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @if ($result === null)
        <x-filament::section>
            <p style="margin: 0;" data-state="empty">{{ __('statistics.filters.pick_clinic') }}</p>
        </x-filament::section>
    @else
        @php($c = $result->cards)

        <p style="{{ $muted }}">{{ __('statistics.min_sample_hint', ['count' => $result->minSample]) }}</p>

        <div wire:loading.class="opacity-50"
             style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 290px), 1fr)); gap: 1rem; align-items: stretch;">

            {{-- 1. No-show rate, before and after --}}
            @php($card = $c['no_show'])
            <x-filament::section :heading="__('statistics.cards.no_show.title')" data-card="no_show" data-state="{{ $card['state'] }}">
                <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                    <p style="{{ $muted }}">
                        {{ $card['activated']
                            ? __('statistics.cards.no_show.activated_on', ['date' => $card['activation_date']])
                            : __('statistics.cards.no_show.not_activated') }}
                    </p>

                    @if (! $card['activated'])
                        @if ($card['before'] === null)
                            <p style="{{ $placeholder }}">{{ __('statistics.no_data') }}</p>
                        @else
                            <p style="{{ $muted }}">{{ __('statistics.cards.no_show.overall') }}</p>
                            <p style="{{ $card['before']['rate'] === null ? $placeholder : $big }}">{{ $pct($card['before']['rate']) ?? __('statistics.insufficient') }}</p>
                            <p style="{{ $muted }}">{{ __('statistics.sample', ['count' => $card['before']['visits']]) }}</p>
                        @endif
                    @else
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                            <div data-side="before">
                                <p style="{{ $muted }}">{{ __('statistics.before') }}</p>
                                @if ($card['no_baseline'])
                                    <p style="{{ $placeholder }}">{{ __('statistics.no_baseline') }}</p>
                                @else
                                    <p style="{{ $card['before']['rate'] === null ? $placeholder : $big }}">{{ $pct($card['before']['rate']) ?? __('statistics.insufficient') }}</p>
                                    <p style="{{ $muted }}">{{ __('statistics.sample', ['count' => $card['before']['visits']]) }}</p>
                                @endif
                            </div>
                            <div data-side="after">
                                <p style="{{ $muted }}">{{ __('statistics.after') }}</p>
                                <p style="{{ $card['after']['rate'] === null ? $placeholder : $big }}">{{ $pct($card['after']['rate']) ?? __('statistics.insufficient') }}</p>
                                <p style="{{ $muted }}">{{ __('statistics.sample', ['count' => $card['after']['visits']]) }}</p>
                            </div>
                        </div>

                        @if ($card['change_points'] !== null)
                            <p style="margin: 0; font-weight: 600;" data-field="change">
                                {{ __('statistics.change') }}:
                                <bdi>{{ __('statistics.points', ['value' => ($card['change_points'] > 0 ? '+' : '').number_format($card['change_points'], 1)]) }}</bdi>
                            </p>
                        @endif

                        @if ($card['recovered_revenue'] !== null)
                            <div>
                                <p style="{{ $muted }}">{{ __('statistics.cards.no_show.recovered_revenue') }}</p>
                                <p style="font-size: 1.3rem; font-weight: 700; margin: 0;" data-field="recovered_revenue"><bdi>{{ $money($card['recovered_revenue']) }}</bdi></p>
                                <p style="{{ $muted }}">{{ __('statistics.cards.no_show.recovered_hint', ['visits' => $card['after']['visits'], 'fee' => $money($card['avg_visit_fee'])]) }}</p>
                            </div>
                        @endif
                    @endif
                </div>
            </x-filament::section>

            {{-- The single-value cards share one shape. --}}
            @foreach ([
                'outside_hours' => [
                    'value' => $c['outside_hours']['count'],
                    'suffix' => ($pct($c['outside_hours']['percent']) ?? '').' '.__('statistics.cards.outside_hours.of_all'),
                    'lines' => [__('statistics.cards.outside_hours.split', ['outside' => $c['outside_hours']['outside_hours'], 'holidays' => $c['outside_hours']['on_holidays']])],
                ],
                'rebooked_slots' => [
                    'value' => $pct($c['rebooked_slots']['fill_rate']),
                    'suffix' => __('statistics.cards.rebooked_slots.fill_rate'),
                    'lines' => [
                        __('statistics.cards.rebooked_slots.split', ['refilled' => $c['rebooked_slots']['refilled'], 'cancelled' => $c['rebooked_slots']['cancelled']]),
                        __('statistics.cards.rebooked_slots.time', ['avg' => $min($c['rebooked_slots']['avg_minutes_to_refill']), 'median' => $min($c['rebooked_slots']['median_minutes_to_refill'])]),
                    ],
                ],
                'waiting_time' => [
                    'value' => $c['waiting_time']['avg_minutes'] === null ? null : $min($c['waiting_time']['avg_minutes']),
                    'suffix' => __('statistics.cards.waiting_time.average'),
                    'lines' => [
                        __('statistics.cards.waiting_time.median').': '.$min($c['waiting_time']['median_minutes']),
                        __('statistics.cards.waiting_time.excluded', ['count' => $c['waiting_time']['excluded']]),
                    ],
                ],
                'link_opens' => [
                    'value' => $pct($c['link_opens']['percent']),
                    'suffix' => '',
                    'lines' => [
                        __('statistics.cards.link_opens.split', ['opened' => $c['link_opens']['opened']]),
                        $c['link_opens']['tracked_since']
                            ? __('statistics.cards.link_opens.since', ['date' => $c['link_opens']['tracked_since']])
                            : __('statistics.cards.link_opens.not_tracked'),
                    ],
                ],
                'on_time' => [
                    'value' => $pct($c['on_time']['percent']),
                    'suffix' => '',
                    'lines' => [__('statistics.cards.on_time.split', ['count' => $c['on_time']['on_time'], 'minutes' => $c['on_time']['tolerance_minutes']])],
                ],
                'whatsapp' => [
                    'value' => $pct($c['whatsapp']['percent']),
                    'suffix' => '',
                    'lines' => [
                        __('statistics.cards.whatsapp.split', [
                            'confirmed' => $c['whatsapp']['confirmed'],
                            'confirmed_percent' => $pct($c['whatsapp']['confirmed_percent']),
                            'cancelled' => $c['whatsapp']['cancelled'],
                            'cancelled_percent' => $pct($c['whatsapp']['cancelled_percent']),
                        ]),
                        __('statistics.cards.whatsapp.reschedule_unavailable'),
                        __('statistics.cards.whatsapp.hint'),
                    ],
                ],
            ] as $key => $view)
                @php($card = $c[$key])
                <x-filament::section :heading="__('statistics.cards.'.$key.'.title')" data-card="{{ $key }}" data-state="{{ $card['state'] }}">
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        @if ($card['state'] === 'ok' && $view['value'] !== null)
                            <p style="{{ $big }}" data-field="value"><bdi>{{ $view['value'] }}</bdi>
                                @if (trim($view['suffix']) !== '')
                                    <span style="font-size: 0.85rem; font-weight: 400; opacity: 0.7;">{{ $view['suffix'] }}</span>
                                @endif
                            </p>
                            @foreach ($view['lines'] as $line)
                                <p style="{{ $muted }}">{{ $line }}</p>
                            @endforeach
                        @else
                            <p style="{{ $placeholder }}" data-field="value">
                                {{ $card['state'] === 'no_data' ? __('statistics.no_data') : __('statistics.insufficient') }}
                            </p>
                        @endif
                        <p style="{{ $muted }}">{{ __('statistics.sample', ['count' => $card['sample']]) }}</p>
                    </div>
                </x-filament::section>
            @endforeach

            {{-- 9. Satisfaction, on the review page's three levels --}}
            @php($card = $c['satisfaction'])
            <x-filament::section :heading="__('statistics.cards.satisfaction.title')" data-card="satisfaction" data-state="{{ $card['state'] }}">
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    @if ($card['state'] === 'ok')
                        <p style="{{ $big }}" data-field="value"><bdi>{{ $pct($card['positive_percent']) }}</bdi>
                            <span style="font-size: 0.85rem; font-weight: 400; opacity: 0.7;">{{ __('statistics.cards.satisfaction.positive') }}</span>
                        </p>
                        @foreach ($card['levels'] as $level => $numbers)
                            <div style="display: flex; flex-direction: column; gap: 0.2rem;">
                                <div style="display: flex; justify-content: space-between; font-size: 0.85rem;">
                                    <span>{{ __('review.staff_rating.'.$level) }}</span>
                                    <bdi>{{ $numbers['count'] }} · {{ $pct($numbers['percent']) }}</bdi>
                                </div>
                                <div style="height: 6px; border-radius: 3px; background: rgba(127,127,127,.2); overflow: hidden;">
                                    <div style="height: 100%; width: {{ $numbers['percent'] ?? 0 }}%; background: rgb(var(--primary-500, 59 130 246));"></div>
                                </div>
                            </div>
                        @endforeach
                    @else
                        <p style="{{ $placeholder }}" data-field="value">
                            {{ $card['state'] === 'no_data' ? __('statistics.no_data') : __('statistics.insufficient') }}
                        </p>
                    @endif
                    <p style="{{ $muted }}">{{ __('statistics.cards.satisfaction.total', ['count' => $card['sample']]) }}</p>
                </div>
            </x-filament::section>
        </div>

        {{-- 10. Top comments: anonymised, newest first --}}
        <x-filament::section :heading="__('statistics.comments.title')" :description="__('statistics.comments.hint')" data-card="comments">
            @if ($result->comments === [])
                <p style="margin: 0; opacity: 0.7;">{{ __('statistics.comments.empty') }}</p>
            @else
                <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.9rem;">
                    @foreach ($result->comments as $comment)
                        <li style="border-inline-start: 3px solid rgba(127,127,127,.35); padding-inline-start: 0.75rem;">
                            <p style="margin: 0 0 0.25rem;">{{ $comment['comment'] }}</p>
                            <p style="{{ $muted }}">{{ __('review.staff_rating.'.$comment['rating']) }} · <bdi>{{ $comment['date'] }}</bdi></p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
