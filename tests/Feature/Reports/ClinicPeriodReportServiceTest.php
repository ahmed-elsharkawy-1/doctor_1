<?php

namespace Tests\Feature\Reports;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Patient;
use App\Models\VisitType;
use App\Services\Reports\ReportPeriod;
use App\Services\V1\Reports\ClinicPeriodReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The numbers behind the doctor's report.
 *
 * Income is what the existing revenue screen already calls income: completed
 * visits at the price frozen onto each booking. Anything else here would let
 * the report and the app disagree about the same day.
 */
class ClinicPeriodReportServiceTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    /** A Sunday: "yesterday" is a Saturday, the first day of the business week. */
    private const TODAY = '2026-10-04';

    private VisitType $checkup;

    private VisitType $followUp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 09:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo']);

        $this->checkup = $this->clinic->visitTypes()->create(['name' => 'كشف', 'price' => 400, 'duration_minutes' => 20, 'is_active' => true]);
        $this->followUp = $this->clinic->visitTypes()->create(['name' => 'إعادة', 'price' => 150, 'duration_minutes' => 10, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_income_counts_completed_visits_at_their_booked_price(): void
    {
        $this->visit('2026-10-03 10:00', $this->checkup, 'done', price: 400);
        // Booked before a price rise: the snapshot, not today's price, counts.
        $this->visit('2026-10-03 11:00', $this->checkup, 'done', price: 350);
        $this->visit('2026-10-03 12:00', $this->checkup, 'no_show');
        $this->visit('2026-10-03 13:00', $this->checkup, 'cancelled');

        $report = $this->day('2026-10-03');

        $this->assertSame(2, $report->completed['count']);
        $this->assertSame(750.0, $report->completed['income']);
    }

    public function test_it_splits_by_the_clinics_own_visit_types(): void
    {
        $this->visit('2026-10-03 10:00', $this->checkup, 'done');
        $this->visit('2026-10-03 11:00', $this->checkup, 'done');
        $this->visit('2026-10-03 12:00', $this->followUp, 'done');

        $byType = collect($this->day('2026-10-03')->byVisitType)->keyBy('name');

        $this->assertSame(2, $byType['كشف']['count']);
        $this->assertSame(800.0, $byType['كشف']['income']);
        $this->assertSame(1, $byType['إعادة']['count']);
        $this->assertSame(150.0, $byType['إعادة']['income']);
    }

    public function test_outcomes_are_counted_for_the_day(): void
    {
        $this->visit('2026-10-03 10:00', $this->checkup, 'done');
        $this->visit('2026-10-03 11:00', $this->checkup, 'no_show');
        $this->visit('2026-10-03 12:00', $this->checkup, 'no_show');
        $this->visit('2026-10-03 13:00', $this->checkup, 'cancelled');
        // Another day: not counted.
        $this->visit('2026-10-02 13:00', $this->checkup, 'cancelled');

        $outcomes = $this->day('2026-10-03')->outcomes;

        $this->assertSame(1, $outcomes['done']);
        $this->assertSame(2, $outcomes['no_show']);
        $this->assertSame(1, $outcomes['cancelled']);
    }

    /** New means the patient's first completed visit at this clinic is inside the period. */
    public function test_new_and_returning_patients(): void
    {
        $returning = $this->patient('منى عبد الله', '01012345678');
        $this->visit('2026-09-01 10:00', $this->checkup, 'done', patient: $returning);
        $this->visit('2026-10-03 10:00', $this->followUp, 'done', patient: $returning);

        $new = $this->patient('سارة أحمد', '01012225521');
        $this->visit('2026-10-03 11:00', $this->checkup, 'done', patient: $new);

        // Booked before but never seen: still new on her first completed visit.
        $neverSeen = $this->patient('هدى سمير', '01223334432');
        $this->visit('2026-09-20 10:00', $this->checkup, 'no_show', patient: $neverSeen);
        $this->visit('2026-10-03 12:00', $this->checkup, 'done', patient: $neverSeen);

        $patients = $this->day('2026-10-03')->patients;

        $this->assertSame(2, $patients['new_count']);
        $this->assertSame(1, $patients['returning_count']);
        $this->assertEqualsCanonicalizing(['سارة أحمد', 'هدى سمير'], array_column($patients['new'], 'name'));

        $sara = collect($patients['new'])->firstWhere('name', 'سارة أحمد');
        $this->assertSame('01012225521', $sara['phone']);
        $this->assertSame('كشف', $sara['visit_type']);
    }

    public function test_a_day_report_counts_the_next_days_bookings(): void
    {
        $this->visit('2026-10-04 10:00', $this->checkup, 'booked');
        $this->visit('2026-10-04 11:00', $this->checkup, 'booked');
        // Cancelled ones are not coming.
        $this->visit('2026-10-04 12:00', $this->checkup, 'cancelled');

        $this->assertSame(2, $this->day('2026-10-03')->nextDayBookings);
    }

    /** The business week runs Saturday to Friday. */
    public function test_a_week_runs_saturday_to_friday_with_a_line_per_day(): void
    {
        $this->visit('2026-09-26 10:00', $this->checkup, 'done'); // Saturday — first day
        $this->visit('2026-10-02 10:00', $this->checkup, 'done'); // Friday — last day
        $this->visit('2026-10-03 10:00', $this->checkup, 'done'); // next Saturday — out

        $report = app(ClinicPeriodReportService::class)->for(
            $this->clinic,
            ReportPeriod::forWeek(Carbon::parse('2026-09-26', 'Africa/Cairo'), $this->today()),
        );

        $this->assertSame(2, $report->completed['count']);
        $this->assertCount(7, $report->daily);
        $this->assertSame('2026-09-26', $report->daily[0]['date']);
        $this->assertSame('2026-10-02', $report->daily[6]['date']);
        $this->assertNull($report->nextDayBookings);
    }

    public function test_a_month_covers_the_calendar_month(): void
    {
        $this->visit('2026-09-01 10:00', $this->checkup, 'done');
        $this->visit('2026-09-30 10:00', $this->checkup, 'done');
        $this->visit('2026-10-01 10:00', $this->checkup, 'done');

        $report = app(ClinicPeriodReportService::class)->for(
            $this->clinic,
            ReportPeriod::forMonth(Carbon::parse('2026-09-01', 'Africa/Cairo'), $this->today()),
        );

        $this->assertSame(2, $report->completed['count']);
        $this->assertCount(30, $report->daily);
    }

    /**
     * A month is read week by week — Saturday to Friday, clipped to the month,
     * so September 2026 (starting on a Tuesday) has a short first week.
     */
    public function test_a_month_breaks_down_by_business_week(): void
    {
        $this->visit('2026-09-01 10:00', $this->checkup, 'done', price: 400); // Tue, first part-week
        $this->visit('2026-09-04 10:00', $this->checkup, 'done', price: 400); // Fri, same week
        $this->visit('2026-09-05 10:00', $this->checkup, 'done', price: 150); // Sat, next week
        $this->visit('2026-09-30 10:00', $this->checkup, 'done', price: 400); // last part-week

        $weekly = app(ClinicPeriodReportService::class)->for(
            $this->clinic,
            ReportPeriod::forMonth(Carbon::parse('2026-09-01', 'Africa/Cairo'), $this->today()),
        )->weekly;

        $this->assertSame(
            [['2026-09-01', '2026-09-04', 2, 800.0], ['2026-09-05', '2026-09-11', 1, 150.0], ['2026-09-12', '2026-09-18', 0, 0.0],
                ['2026-09-19', '2026-09-25', 0, 0.0], ['2026-09-26', '2026-09-30', 1, 400.0]],
            array_map(fn ($w) => [$w['from'], $w['to'], $w['count'], $w['income']], $weekly),
        );
    }

    public function test_only_a_month_has_weeks(): void
    {
        $this->assertSame([], $this->day('2026-10-03')->weekly);
    }

    /** The day is compared with the same weekday a week earlier — clinic days differ. */
    public function test_a_day_is_compared_with_the_same_day_last_week(): void
    {
        $this->visit('2026-09-26 10:00', $this->checkup, 'done', price: 400);
        $this->visit('2026-10-03 10:00', $this->checkup, 'done', price: 400);
        $this->visit('2026-10-03 11:00', $this->checkup, 'done', price: 400);

        $completed = $this->day('2026-10-03')->completed;

        $this->assertSame(1, $completed['previous_count']);
        $this->assertSame(400.0, $completed['previous_income']);
    }

    /*
    |--------------------------------------------------------------------------
    | Was the clinic open?
    |--------------------------------------------------------------------------
    */

    /** A dated holiday wins over everything, and carries its note. */
    public function test_a_holiday_is_named_as_one(): void
    {
        $this->clinic->holidays()->create(['date' => '2026-10-03', 'note' => 'إجازة عيد']);

        $this->assertSame(['kind' => 'holiday', 'note' => 'إجازة عيد'], $this->day('2026-10-03')->dayStatus);
    }

    public function test_a_weekly_day_off_is_closed(): void
    {
        $this->openOnly(['SATURDAY']);

        $this->assertSame('closed', $this->day('2026-10-02')->dayStatus['kind']); // a Friday
        $this->assertSame('open', $this->day('2026-10-03')->dayStatus['kind']);   // a Saturday
    }

    public function test_every_day_of_a_week_says_whether_it_was_open(): void
    {
        $this->openOnly(['SATURDAY', 'SUNDAY']);
        $this->clinic->holidays()->create(['date' => '2026-09-27', 'note' => null]); // the Sunday

        $report = app(ClinicPeriodReportService::class)->for(
            $this->clinic,
            ReportPeriod::forWeek(Carbon::parse('2026-09-26', 'Africa/Cairo'), $this->today()),
        );

        $kinds = array_column($report->daily, 'kind', 'date');
        $this->assertSame('open', $kinds['2026-09-26']);
        $this->assertSame('holiday', $kinds['2026-09-27']);
        $this->assertSame('closed', $kinds['2026-09-28']);
        $this->assertNull($report->dayStatus);
    }

    /*
    |--------------------------------------------------------------------------
    | How it compares, and what went wrong
    |--------------------------------------------------------------------------
    */

    public function test_the_change_against_the_comparison_period(): void
    {
        $this->visit('2026-09-26 10:00', $this->checkup, 'done', price: 400);
        $this->visit('2026-10-03 10:00', $this->checkup, 'done', price: 400);
        $this->visit('2026-10-03 11:00', $this->checkup, 'done', price: 100);

        $completed = $this->day('2026-10-03')->completed;

        $this->assertSame(25.0, $completed['change_percent']);
        $this->assertSame('up', $completed['direction']);
        $this->assertSame('2026-09-26', $completed['previous_from']);
        $this->assertSame('2026-09-26', $completed['previous_to']);
    }

    /** Growth from nothing is not a percentage worth showing. */
    public function test_no_percentage_when_the_comparison_period_was_empty(): void
    {
        $this->visit('2026-10-03 10:00', $this->checkup, 'done');

        $this->assertNull($this->day('2026-10-03')->completed['change_percent']);
    }

    /** No-shows out of those who were expected — cancellations warned the clinic. */
    public function test_the_no_show_rate_and_total_bookings(): void
    {
        $this->visit('2026-10-03 10:00', $this->checkup, 'done');
        $this->visit('2026-10-03 10:30', $this->checkup, 'done');
        $this->visit('2026-10-03 11:00', $this->checkup, 'done');
        $this->visit('2026-10-03 11:30', $this->checkup, 'no_show');
        $this->visit('2026-10-03 12:00', $this->checkup, 'cancelled');

        $outcomes = $this->day('2026-10-03')->outcomes;

        $this->assertSame(5, $outcomes['total']);
        $this->assertSame(25.0, $outcomes['no_show_rate']);
    }

    public function test_another_clinics_bookings_never_count(): void
    {
        $other = $this->otherClinic();
        Booking::factory()->forClinic($other)->at(Carbon::parse('2026-10-03 10:00', 'Africa/Cairo'))->done()->create();

        $report = $this->day('2026-10-03');

        $this->assertSame(0, $report->completed['count']);
        $this->assertSame(0, $report->patients['new_count']);
    }

    /** @param list<string> $days DayOfWeek case names */
    private function openOnly(array $days): void
    {
        foreach (DayOfWeek::cases() as $day) {
            $this->clinic->scheduleFor($day)?->update(['is_open' => in_array($day->name, $days, true)]);
        }
    }

    private function day(string $date)
    {
        return app(ClinicPeriodReportService::class)->for(
            $this->clinic,
            ReportPeriod::forDay(Carbon::parse($date, 'Africa/Cairo')),
        );
    }

    private function today(): Carbon
    {
        return Carbon::now('Africa/Cairo')->startOfDay();
    }

    private function patient(string $name, string $phone): Patient
    {
        return Patient::factory()->for($this->clinic)->create([
            'name' => $name,
            'phone' => '+2'.$phone,
        ]);
    }

    private function visit(string $at, VisitType $type, string $status, ?Patient $patient = null, ?float $price = null): Booking
    {
        $factory = Booking::factory()->forClinic($this->clinic)->at(Carbon::parse($at, 'Africa/Cairo'));

        $factory = match ($status) {
            'done' => $factory->done(),
            'no_show' => $factory->noShow(),
            'cancelled' => $factory->cancelled(),
            default => $factory,
        };

        return $factory->create(array_filter([
            'visit_type_id' => $type->id,
            'price' => $price ?? $type->price,
            'patient_id' => $patient?->id,
        ], fn ($value) => $value !== null));
    }
}
