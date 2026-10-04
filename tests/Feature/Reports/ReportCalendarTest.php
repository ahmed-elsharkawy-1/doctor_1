<?php

namespace Tests\Feature\Reports;

use App\Services\V1\Reports\ReportCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Which days, weeks and months a doctor may open.
 *
 * One rule, read by the page and by the morning message alike: a link the
 * message sends must always be one the page will open.
 */
class ReportCalendarTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    /** Sunday 4 October: yesterday is Saturday 3rd, this week began that Saturday. */
    private const TODAY = '2026-10-04';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 09:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_days_are_yesterday_and_the_six_before_it(): void
    {
        $days = array_column($this->calendar()->days($this->clinic), 'date');

        $this->assertSame([
            '2026-10-03', '2026-10-02', '2026-10-01', '2026-09-30',
            '2026-09-29', '2026-09-28', '2026-09-27',
        ], $days);
    }

    public function test_the_periods_are_this_and_last_week_this_month_and_six_months_before(): void
    {
        $periods = $this->calendar()->periods($this->clinic);

        $this->assertSame(
            ['week:2026-10-03', 'week:2026-09-26', 'month:2026-10', 'month:2026-09', 'month:2026-08',
                'month:2026-07', 'month:2026-06', 'month:2026-05', 'month:2026-04'],
            array_map(fn ($p) => $p['type'].':'.$p['value'], $periods),
        );
    }

    public function test_a_listed_day_resolves(): void
    {
        $period = $this->calendar()->resolve($this->clinic, 'day', '2026-09-27');

        $this->assertSame('2026-09-27', $period->from->toDateString());
        $this->assertSame('2026-09-27', $period->to->toDateString());
    }

    public function test_today_and_older_days_do_not_resolve(): void
    {
        $this->assertNull($this->calendar()->resolve($this->clinic, 'day', self::TODAY));
        $this->assertNull($this->calendar()->resolve($this->clinic, 'day', '2026-09-26'));
        $this->assertNull($this->calendar()->resolve($this->clinic, 'day', 'not-a-date'));
    }

    public function test_last_week_runs_saturday_to_friday(): void
    {
        $period = $this->calendar()->resolve($this->clinic, 'week', '2026-09-26');

        $this->assertSame('2026-09-26', $period->from->toDateString());
        $this->assertSame('2026-10-02', $period->to->toDateString());
    }

    /** A week only by its Saturday — any other day is not a week. */
    public function test_a_week_must_start_on_a_listed_saturday(): void
    {
        $this->assertNull($this->calendar()->resolve($this->clinic, 'week', '2026-09-27'));
        $this->assertNull($this->calendar()->resolve($this->clinic, 'week', '2026-09-19'));
    }

    public function test_this_month_stops_at_today_and_older_months_are_refused(): void
    {
        $this->assertSame('2026-10-04', $this->calendar()->resolve($this->clinic, 'month', '2026-10')->to->toDateString());
        $this->assertSame('2026-04-30', $this->calendar()->resolve($this->clinic, 'month', '2026-04')->to->toDateString());
        $this->assertNull($this->calendar()->resolve($this->clinic, 'month', '2026-03'));
        $this->assertNull($this->calendar()->resolve($this->clinic, 'month', '2026-13'));
    }

    public function test_an_unknown_kind_does_not_resolve(): void
    {
        $this->assertNull($this->calendar()->resolve($this->clinic, 'year', '2026'));
    }

    /**
     * The morning message picks one period: the month on the 1st, the week on
     * Saturday, the day otherwise — always one the page will open.
     */
    public function test_the_morning_period_follows_the_calendar(): void
    {
        $this->assertSame(['day', '2026-10-03'], $this->morning('2026-10-04')); // Sunday
        $this->assertSame(['week', '2026-09-26'], $this->morning('2026-10-03')); // Saturday
        $this->assertSame(['month', '2026-09'], $this->morning('2026-10-01')); // the 1st
        // The 1st on a Saturday: the month wins, one message only.
        $this->assertSame(['month', '2026-07'], $this->morning('2026-08-01'));
    }

    private function morning(string $today): array
    {
        Carbon::setTestNow(Carbon::parse($today.' 09:00', 'Africa/Cairo'));

        [$type, $value] = $this->calendar()->morning($this->clinic);

        $this->assertNotNull($this->calendar()->resolve($this->clinic, $type, $value), "The morning link for $today must open.");

        return [$type, $value];
    }

    private function calendar(): ReportCalendar
    {
        return app(ReportCalendar::class);
    }
}
