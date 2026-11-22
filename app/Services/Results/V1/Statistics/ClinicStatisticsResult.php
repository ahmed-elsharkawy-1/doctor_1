<?php

namespace App\Services\Results\V1\Statistics;

/**
 * One clinic's statistics for a date range. The page's cards and the CSV
 * export both read from here, so the two can never show different numbers.
 */
final class ClinicStatisticsResult
{
    /**
     * @param  array<string, array<string, mixed>>  $cards  keyed by metric, each with `state` and `sample`
     * @param  list<array{rating: string, date: string, comment: string}>  $comments
     */
    public function __construct(
        public readonly int $clinicId,
        public readonly string $clinicName,
        public readonly string $from,
        public readonly string $to,
        public readonly int $minSample,
        public readonly array $cards,
        public readonly array $comments,
    ) {}

    /**
     * The export: a header, then one row per number. A value too thin to
     * show on the page is written as "Insufficient data" here too.
     *
     * @return list<list<string>>
     */
    public function csvRows(): array
    {
        $rows = [[
            __('statistics.csv.clinic'),
            __('statistics.csv.from'),
            __('statistics.csv.to'),
            __('statistics.csv.metric'),
            __('statistics.csv.measure'),
            __('statistics.csv.value'),
            __('statistics.csv.sample'),
        ]];

        $add = function (string $metric, string $measure, mixed $value, int|string $sample) use (&$rows): void {
            $rows[] = [
                $this->clinicName,
                $this->from,
                $this->to,
                __("statistics.cards.{$metric}.title"),
                __("statistics.measures.{$measure}"),
                $value === null ? __('statistics.insufficient') : (string) $value,
                (string) $sample,
            ];
        };

        $c = $this->cards;

        $noShow = $c['no_show'];
        $add('no_show', 'activation_date', $noShow['activation_date'] ?? __('statistics.cards.no_show.not_activated'), '');
        if ($noShow['no_baseline']) {
            $rows[] = [$this->clinicName, $this->from, $this->to, __('statistics.cards.no_show.title'), __('statistics.measures.before_rate'), __('statistics.no_baseline'), '0'];
        } elseif ($noShow['before'] !== null) {
            $measure = $noShow['activated'] ? 'before_rate' : 'overall_rate';
            $add('no_show', $measure, $noShow['before']['rate'], $noShow['before']['visits']);
        }
        if ($noShow['after'] !== null) {
            $add('no_show', 'after_rate', $noShow['after']['rate'], $noShow['after']['visits']);
            if (! $noShow['no_baseline']) {
                $add('no_show', 'change_points', $noShow['change_points'], $noShow['sample']);
                $add('no_show', 'avg_visit_fee', $noShow['avg_visit_fee'], $noShow['after']['visits']);
                $add('no_show', 'recovered_revenue', $noShow['recovered_revenue'], $noShow['after']['visits']);
            }
        }

        $this->addCounted($add, 'outside_hours', ['count', 'outside_hours', 'on_holidays'], ['percent']);
        $this->addCounted($add, 'rebooked_slots', ['cancelled', 'refilled'], ['fill_rate']);
        $add('rebooked_slots', 'avg_minutes_to_refill', $c['rebooked_slots']['avg_minutes_to_refill'], $c['rebooked_slots']['refilled']);
        $add('rebooked_slots', 'median_minutes_to_refill', $c['rebooked_slots']['median_minutes_to_refill'], $c['rebooked_slots']['refilled']);
        $this->addCounted($add, 'waiting_time', ['excluded'], ['avg_minutes', 'median_minutes']);
        $this->addCounted($add, 'link_opens', ['opened'], ['percent']);
        $this->addCounted($add, 'on_time', ['on_time'], ['percent']);
        $add('on_time', 'tolerance_minutes', $c['on_time']['tolerance_minutes'], '');
        $this->addCounted($add, 'whatsapp', ['responded', 'confirmed', 'cancelled'], ['percent', 'confirmed_percent', 'cancelled_percent']);
        $add('whatsapp', 'rescheduled', __('statistics.not_supported'), $c['whatsapp']['sample']);
        $this->addCounted($add, 'satisfaction', ['positive'], ['positive_percent']);

        foreach ($c['satisfaction']['levels'] as $level => $numbers) {
            $add('satisfaction', "level_{$level}", $this->gate('satisfaction', $numbers['count']), $c['satisfaction']['sample']);
            $add('satisfaction', "level_{$level}_percent", $numbers['percent'], $c['satisfaction']['sample']);
        }

        foreach ($this->comments as $comment) {
            $rows[] = [
                $this->clinicName,
                $comment['date'],
                $comment['date'],
                __('statistics.comments.title'),
                __("review.staff_rating.{$comment['rating']}"),
                $comment['comment'],
                '',
            ];
        }

        return $rows;
    }

    /**
     * Counts are as thin as the rates beside them, so they follow the same
     * "Insufficient data" rule rather than standing alone.
     *
     * @param  list<string>  $counts
     * @param  list<string>  $rates  already null below the minimum
     */
    private function addCounted(callable $add, string $metric, array $counts, array $rates): void
    {
        $card = $this->cards[$metric];

        foreach ($counts as $key) {
            $add($metric, $key, $this->gate($metric, $card[$key] ?? null), $card['sample']);
        }

        foreach ($rates as $key) {
            $add($metric, $key, $card[$key] ?? null, $card['sample']);
        }
    }

    private function gate(string $metric, mixed $value): mixed
    {
        return $this->cards[$metric]['state'] === 'ok' ? $value : null;
    }
}
