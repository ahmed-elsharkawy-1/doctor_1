<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Livewire\App\Delay;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Queue\DelayService;
use App\Services\V1\Settings\ScheduleService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-22: an emergency delay over chosen branches, each by its own time, with
 * patients unticked. Saturday 2026-12-05: Gedeila 13:00–16:00, Al-Tur'a
 * 17:00–20:00; now is 12:00.
 */
class BranchDelayTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const SATURDAY = '2026-12-05';

    private Branch $gedeila;

    private Branch $tura;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::SATURDAY.' 12:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);
        Queue::fake();

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);
        $this->gedeila = $this->clinic->firstBranch();
        $this->tura = app(BranchService::class)->create($this->clinic, new BranchData('عيادة الترعة', 'الترعة', 31.1, 31.4, null), $this->owner);

        foreach ([[$this->gedeila, '13:00', '16:00'], [$this->tura, '17:00', '20:00']] as [$branch, $from, $to]) {
            app(ScheduleService::class)->updateDay($branch, ScheduleDayData::fromArray(DayOfWeek::SATURDAY, [
                'is_open' => true,
                'periods' => [['start_time' => $from, 'end_time' => $to]],
            ]));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(Branch $branch, string $time, BookingStatus $status = BookingStatus::BOOKED): Booking
    {
        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::SATURDAY.' '.$time, 'Africa/Cairo'), 20)
            ->create([
                'branch_id' => $branch->id,
                'status' => $status,
                'patient_id' => Patient::factory()->create(['clinic_id' => $this->clinic->id])->id,
            ]);
    }

    private function time(Booking $booking): string
    {
        return $booking->fresh()->start_at->format('H:i');
    }

    public function test_each_branch_moves_by_its_own_time_and_unticked_patients_stay(): void
    {
        $inRoom = $this->booking($this->gedeila, '13:00', BookingStatus::WITH_DOCTOR);
        $g1 = $this->booking($this->gedeila, '13:20');
        $g2 = $this->booking($this->gedeila, '13:40');
        $t1 = $this->booking($this->tura, '17:00');
        $t2 = $this->booking($this->tura, '17:20');

        $outcome = app(DelayService::class)->delayBranches($this->clinic, $this->owner, Carbon::parse(self::SATURDAY), [
            $this->gedeila->id => ['minutes' => 30, 'exclude' => [$g2->id]],
            $this->tura->id => ['minutes' => 60],
        ]);

        $this->assertSame('13:00', $this->time($inRoom));
        $this->assertSame('13:50', $this->time($g1));
        $this->assertSame('13:40', $this->time($g2));
        $this->assertSame('18:00', $this->time($t1));
        $this->assertSame('18:20', $this->time($t2));
        $this->assertSame(3, $outcome->bookings->count());
    }

    public function test_a_branch_left_out_is_untouched(): void
    {
        $t1 = $this->booking($this->tura, '17:00');
        $this->booking($this->gedeila, '13:20');

        app(DelayService::class)->delayBranches($this->clinic, $this->owner, Carbon::parse(self::SATURDAY), [
            $this->gedeila->id => ['minutes' => 30],
        ]);

        $this->assertSame('17:00', $this->time($t1));
    }

    public function test_a_team_member_delays_only_her_branches(): void
    {
        $this->booking($this->gedeila, '13:20');
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();

        $this->expectException(ApiException::class);
        app(DelayService::class)->delayBranches($this->clinic, $member, Carbon::parse(self::SATURDAY), [
            $this->gedeila->id => ['minutes' => 30],
        ]);
    }

    public function test_a_time_that_is_not_offered_or_a_past_day_is_refused(): void
    {
        $this->booking($this->gedeila, '13:20');
        $service = app(DelayService::class);

        try {
            $service->delayBranches($this->clinic, $this->owner, Carbon::parse(self::SATURDAY), [$this->gedeila->id => ['minutes' => 7]]);
            $this->fail('An odd delay was accepted.');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        $this->expectException(ApiException::class);
        $service->delayBranches($this->clinic, $this->owner, Carbon::parse('2026-12-04'), [$this->gedeila->id => ['minutes' => 30]]);
    }

    public function test_a_double_tap_moves_once(): void
    {
        $g1 = $this->booking($this->gedeila, '13:20');
        $plan = [$this->gedeila->id => ['minutes' => 30]];

        app(DelayService::class)->delayBranches($this->clinic, $this->owner, Carbon::parse(self::SATURDAY), $plan);
        $again = app(DelayService::class)->delayBranches($this->clinic, $this->owner, Carbon::parse(self::SATURDAY), $plan);

        $this->assertTrue($again->replayed);
        $this->assertSame('13:50', $this->time($g1));
    }

    public function test_the_api_lists_patients_by_branch_and_delays_them(): void
    {
        $g1 = $this->booking($this->gedeila, '13:20');
        $t1 = $this->booking($this->tura, '17:00');
        Sanctum::actingAs($this->owner);

        $this->getJson(route('api.v1.emergency.branches.candidates', ['date' => self::SATURDAY]))
            ->assertOk()
            ->assertJsonCount(2, 'data.branches')
            ->assertJsonPath('data.branches.1.bookings.0.booking_id', $t1->id);

        $this->postJson(route('api.v1.emergency.branches'), [
            'date' => self::SATURDAY,
            'branches' => [
                ['branch_id' => $this->gedeila->id, 'minutes' => 15],
                ['branch_id' => $this->tura->id, 'minutes' => 60, 'exclude_booking_ids' => [$t1->id]],
            ],
        ])->assertOk()->assertJsonPath('data.moved_count', 1);

        $this->assertSame('13:35', $this->time($g1));
        $this->assertSame('17:00', $this->time($t1));
    }

    public function test_the_screen_shows_branches_and_delays_the_ticked_patients(): void
    {
        $g1 = $this->booking($this->gedeila, '13:20');
        $t1 = $this->booking($this->tura, '17:00');

        Livewire::actingAs($this->owner)
            ->test(Delay::class)
            ->assertSee('عيادة الترعة')
            ->set('day', self::SATURDAY)
            ->set('minutesBy.'.$this->tura->id, 60)
            ->call('toggleExcluded', $g1->id)
            ->call('confirm')
            ->assertSee(__('app.delay.confirm_branches'))
            ->call('delayBranches')
            ->assertSet('failed', false);

        $this->assertSame('13:20', $this->time($g1));
        $this->assertSame('18:00', $this->time($t1));
    }
}
