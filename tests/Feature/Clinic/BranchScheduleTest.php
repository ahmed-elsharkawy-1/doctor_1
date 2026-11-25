<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Livewire\App\Settings\Holidays;
use App\Livewire\App\Settings\Hours;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\ClinicHoliday;
use App\Models\User;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Settings\HolidayService;
use App\Services\V1\Settings\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-20: each branch has its own week; holidays close every branch or some.
 * Saturday 2026-12-05: Gedeila 13:00–16:00, Al-Tur'a 17:00–22:00.
 */
class BranchScheduleTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Branch $gedeila;

    private Branch $tura;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);

        $this->setUpClinic();
        $this->gedeila = $this->clinic->firstBranch();
        $this->tura = app(BranchService::class)->create(
            $this->clinic,
            new BranchData('عيادة الترعة', 'الترعة', 31.1, 31.4, null),
            $this->owner,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function open(Branch $branch, DayOfWeek $day, array $periods)
    {
        return app(ScheduleService::class)->updateDay($branch, ScheduleDayData::fromArray($day, [
            'is_open' => true,
            'periods' => array_map(fn ($p) => ['start_time' => $p[0], 'end_time' => $p[1]], $periods),
        ]));
    }

    public function test_every_existing_week_moves_to_the_clinics_first_branch(): void
    {
        // As before the migration: one week per clinic, on no branch.
        $this->tura->schedules()->delete();
        DB::table('clinic_schedules')->update(['branch_id' => null]);

        (require database_path('migrations/2026_11_27_100000_scope_schedules_and_holidays_to_branches.php'))->backfillSchedules();

        $this->assertSame(7, $this->gedeila->schedules()->count());
    }

    public function test_a_new_branch_starts_closed_every_day(): void
    {
        $this->assertSame(7, $this->tura->schedules()->count());
        $this->assertFalse($this->tura->hasWorkingHours());
    }

    public function test_each_branch_keeps_its_own_week(): void
    {
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['13:00', '16:00']]);
        $this->open($this->tura, DayOfWeek::SATURDAY, [['17:00', '22:00']]);

        $this->assertSame('13:00', $this->gedeila->scheduleFor(DayOfWeek::SATURDAY)->periods->first()->startTime());
        $this->assertSame('17:00', $this->tura->scheduleFor(DayOfWeek::SATURDAY)->periods->first()->startTime());
        // A day with no period at a branch is that branch's day off.
        $this->assertFalse($this->tura->scheduleFor(DayOfWeek::THURSDAY)->is_open);
    }

    public function test_the_doctor_cannot_be_at_two_branches_at_once(): void
    {
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['13:00', '16:00']]);

        try {
            $this->open($this->tura, DayOfWeek::SATURDAY, [['15:00', '20:00']]);
            $this->fail('An overlap with another branch was saved.');
        } catch (ApiException $e) {
            $this->assertSame('SCHEDULE_BRANCH_CONFLICT', $e->errorCode->value);
            $this->assertStringContainsString('عيادة', $e->getMessage());
        }

        $this->assertFalse($this->tura->scheduleFor(DayOfWeek::SATURDAY)->is_open);
    }

    public function test_an_evening_past_midnight_at_one_branch_blocks_the_next_morning_at_another(): void
    {
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['19:00', '02:00']]);

        $this->expectException(ApiException::class);
        $this->open($this->tura, DayOfWeek::SUNDAY, [['01:00', '05:00']]);
    }

    public function test_a_short_gap_between_branches_is_saved_with_a_warning(): void
    {
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['13:00', '16:00']]);

        $update = $this->open($this->tura, DayOfWeek::SATURDAY, [['16:15', '22:00']]);

        $this->assertTrue($update->schedule->is_open);
        $this->assertSame([[
            'branch_id' => $this->gedeila->id,
            'branch' => $this->gedeila->name,
            'from' => '13:00',
            'to' => '16:00',
            'minutes' => 15,
        ]], $update->shortGaps);

        $this->assertSame([], $this->open($this->tura, DayOfWeek::SATURDAY, [['17:00', '22:00']])->shortGaps);
    }

    public function test_bookings_left_outside_the_new_hours_are_counted_not_moved(): void
    {
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['09:00', '16:00']]);
        $booking = Booking::factory()->forClinic($this->clinic)->at(Carbon::parse('2026-12-05 10:00', 'Africa/Cairo'))->create();

        $update = $this->open($this->gedeila, DayOfWeek::SATURDAY, [['13:00', '16:00']]);

        $this->assertSame(1, $update->bookingsOutsideHours);
        $this->assertSame('2026-12-05 10:00', $booking->fresh()->start_at->format('Y-m-d H:i'));
    }

    public function test_the_api_edits_the_branch_the_app_is_on(): void
    {
        Sanctum::actingAs($this->owner);
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['13:00', '16:00']]);

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->putJson(route('api.v1.schedule.update', DayOfWeek::SATURDAY->value), [
                'is_open' => true,
                'periods' => [['start_time' => '16:10', 'end_time' => '22:00']],
            ])
            ->assertOk()
            ->assertJsonPath('data.short_gaps.0.minutes', 10)
            ->assertJsonPath('data.bookings_outside_hours', 0);

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->getJson(route('api.v1.schedule.index'))
            ->assertJsonPath('data.days.0.periods.0.start_time.value', '16:10');

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->putJson(route('api.v1.schedule.update', DayOfWeek::SATURDAY->value), [
                'is_open' => true,
                'periods' => [['start_time' => '14:00', 'end_time' => '22:00']],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SCHEDULE_BRANCH_CONFLICT');
    }

    public function test_the_hours_screen_edits_the_branch_picked_on_it(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Hours::class)
            ->assertSee(__('app.settings.hours_overview'))
            ->set('branchId', $this->tura->id)
            ->call('toggleDay', DayOfWeek::MONDAY->value)
            ->set('week.'.DayOfWeek::MONDAY->value.'.periods.0', ['start_time' => '17:00', 'end_time' => '21:00'])
            ->call('saveDay', DayOfWeek::MONDAY->value)
            ->assertSet('failed', false);

        $this->assertTrue($this->tura->scheduleFor(DayOfWeek::MONDAY)->is_open);
        $this->assertFalse($this->gedeila->scheduleFor(DayOfWeek::MONDAY)->is_open);
    }

    public function test_a_holiday_closes_every_branch_by_default_including_later_ones(): void
    {
        app(HolidayService::class)->create($this->clinic, '2026-12-10', 'إجازة', actor: $this->owner);
        $later = Branch::factory()->create(['clinic_id' => $this->clinic->id]);

        $this->assertNull(ClinicHoliday::query()->sole()->branch_id);
        $this->assertTrue($this->gedeila->isClosedOn('2026-12-10'));
        $this->assertTrue($later->isClosedOn('2026-12-10'));
    }

    public function test_a_holiday_can_close_one_branch_for_a_range(): void
    {
        app(HolidayService::class)->create(
            $this->clinic, '2026-12-10', null, endDate: '2026-12-12', branchIds: [$this->tura->id], actor: $this->owner,
        );

        $this->assertSame(3, ClinicHoliday::query()->where('branch_id', $this->tura->id)->count());
        $this->assertTrue($this->tura->isClosedOn('2026-12-11'));
        $this->assertFalse($this->gedeila->isClosedOn('2026-12-11'));

        // The other branch can still close the same day for itself.
        app(HolidayService::class)->create($this->clinic, '2026-12-11', null, branchIds: [$this->gedeila->id], actor: $this->owner);
        $this->assertTrue($this->gedeila->isClosedOn('2026-12-11'));
    }

    public function test_removing_one_day_of_a_range_removes_the_range(): void
    {
        $first = app(HolidayService::class)->create($this->clinic, '2026-12-10', null, endDate: '2026-12-14', actor: $this->owner);

        app(HolidayService::class)->delete($this->clinic, $first->id, $this->owner);

        $this->assertSame(0, ClinicHoliday::query()->count());
    }

    public function test_a_day_already_off_is_refused_and_named(): void
    {
        app(HolidayService::class)->create($this->clinic, '2026-12-11', null, branchIds: [$this->tura->id], actor: $this->owner);

        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.holidays.store'), ['date' => '2026-12-10', 'end_date' => '2026-12-12'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HOLIDAY_ALREADY_EXISTS')
            ->assertJsonPath('error.details.dates', ['2026-12-11']);
    }

    public function test_bookings_in_the_range_are_counted_and_need_confirming(): void
    {
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse('2026-12-11 18:00', 'Africa/Cairo'))->create();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.holidays.store'), ['date' => '2026-12-10', 'end_date' => '2026-12-12'])
            ->assertStatus(409)
            ->assertJsonPath('error.details.bookings_count', 1);

        $this->postJson(route('api.v1.holidays.store'), ['date' => '2026-12-10', 'end_date' => '2026-12-12', 'force' => true])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', null);

        $this->assertSame(3, ClinicHoliday::query()->count());
    }

    public function test_a_range_that_runs_backwards_or_too_long_is_refused(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.holidays.store'), ['date' => '2026-12-10', 'end_date' => '2027-06-10'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'HOLIDAY_RANGE_INVALID');
        $this->postJson(route('api.v1.holidays.store'), ['date' => '2026-12-10', 'end_date' => '2026-12-09'])
            ->assertUnprocessable();
    }

    public function test_a_team_members_all_branches_means_their_own(): void
    {
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();
        $everyBranch = app(HolidayService::class)->create($this->clinic, '2026-12-20', null, actor: $this->owner);

        $mine = app(HolidayService::class)->create($this->clinic, '2026-12-10', null, actor: $member);

        $this->assertSame($this->tura->id, $mine->branch_id);
        $this->assertFalse($this->gedeila->isClosedOn('2026-12-10'));

        // And they can't lift a holiday that closes branches they don't work at.
        $this->expectException(ApiException::class);
        app(HolidayService::class)->delete($this->clinic, $everyBranch->id, $member);
    }

    public function test_the_holidays_screen_closes_chosen_branches(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Holidays::class)
            ->assertSee(__('app.settings.holiday_all_branches'))
            ->set('scope', 'some')
            ->set('branchIds', [(string) $this->tura->id])
            ->set('date', '2026-12-10')
            ->set('endDate', '2026-12-11')
            ->call('add')
            ->assertSet('failed', false);

        $this->assertSame(2, ClinicHoliday::query()->where('branch_id', $this->tura->id)->count());
        $this->assertSame(0, ClinicHoliday::query()->whereNull('branch_id')->count());
    }

    public function test_the_app_only_gets_the_holidays_that_close_its_branch(): void
    {
        app(HolidayService::class)->create($this->clinic, '2026-12-10', null, branchIds: [$this->tura->id], actor: $this->owner);
        app(HolidayService::class)->create($this->clinic, '2026-12-11', null, actor: $this->owner);
        Sanctum::actingAs($this->owner);

        $this->withHeader('X-Branch-Id', (string) $this->gedeila->id)
            ->getJson(route('api.v1.bootstrap'))
            ->assertJsonCount(1, 'data.holidays')
            ->assertJsonPath('data.holidays.0.branch_id', null);
    }

    public function test_another_branchs_patients_are_not_counted_as_outside_these_hours(): void
    {
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['13:00', '16:00']]);
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse('2026-12-05 14:00', 'Africa/Cairo'))->create();

        $update = $this->open($this->tura, DayOfWeek::SATURDAY, [['17:00', '22:00']]);

        $this->assertSame(0, $update->bookingsOutsideHours);
    }

    public function test_a_refused_day_keeps_what_was_typed_and_says_why_on_its_card(): void
    {
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['13:00', '16:00']]);
        $saturday = DayOfWeek::SATURDAY->value;

        Livewire::actingAs($this->owner)
            ->test(Hours::class)
            ->set('branchId', $this->tura->id)
            ->call('toggleDay', $saturday)
            ->set("week.{$saturday}.periods.0", ['start_time' => '14:00', 'end_time' => '18:00'])
            ->call('saveDay', $saturday)
            ->assertSet("week.{$saturday}.periods.0.start_time", '14:00')
            ->assertSet("dayMessages.{$saturday}.failed", true)
            ->assertSet('notice', null)
            ->assertSee('عيادة');
    }

    public function test_a_saved_day_keeps_unsaved_edits_on_the_others_and_warns_on_its_card(): void
    {
        $this->open($this->gedeila, DayOfWeek::SATURDAY, [['13:00', '16:00']]);
        [$saturday, $monday] = [DayOfWeek::SATURDAY->value, DayOfWeek::MONDAY->value];

        Livewire::actingAs($this->owner)
            ->test(Hours::class)
            ->set('branchId', $this->tura->id)
            ->call('toggleDay', $monday)
            ->call('toggleDay', $saturday)
            ->set("week.{$saturday}.periods.0", ['start_time' => '16:10', 'end_time' => '20:00'])
            ->call('saveDay', $saturday)
            ->assertSet("dayMessages.{$saturday}.failed", false)
            ->assertSet("dayMessages.{$saturday}.warnings.0", fn ($w) => str_contains($w, '10'))
            // Monday was opened but not saved: still open on screen.
            ->assertSet("week.{$monday}.is_open", true);
    }
}
