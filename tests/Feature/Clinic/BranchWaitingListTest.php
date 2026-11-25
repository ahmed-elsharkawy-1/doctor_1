<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Booking\BookingData;
use App\DTOs\V1\Branches\BranchData;
use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\BookingKind;
use App\Enums\BookingSource;
use App\Enums\DayOfWeek;
use App\Enums\WaitingListStatus;
use App\Exceptions\ApiException;
use App\Livewire\App\Settings\Branches;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Models\WaitingListEntry;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Settings\ScheduleService;
use App\Services\V1\WaitingList\WaitingListService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-21: a waiting list belongs to one branch on one day, and each branch
 * switches its own on or off. Saturday 2026-12-05: Gedeila 13:00–16:00,
 * Al-Tur'a 17:00–20:00.
 */
class BranchWaitingListTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const SATURDAY = '2026-12-05';

    private Branch $gedeila;

    private Branch $tura;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-05 10:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);
        Queue::fake();

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);
        $this->clinic->update(['waiting_list_enabled' => true, 'waiting_list_max_per_day' => 2]);
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

    private function join(Branch $branch, string $name): WaitingListEntry
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id, 'name' => $name]);

        return app(WaitingListService::class)->join(
            $this->clinic, $patient, $this->clinic->visitTypes()->first(), self::SATURDAY, BookingSource::CLINIC, $this->owner, $branch->refresh(),
        );
    }

    public function test_the_first_branch_keeps_the_clinics_list_and_a_new_one_starts_without(): void
    {
        $this->assertTrue($this->gedeila->refresh()->offersWaitingList());
        $this->assertFalse($this->tura->refresh()->offersWaitingList());
    }

    public function test_a_branch_without_a_list_takes_no_one(): void
    {
        $this->expectException(ApiException::class);
        $this->join($this->tura, 'سارة');
    }

    public function test_a_list_belongs_to_one_branch_and_day_and_fills_per_branch(): void
    {
        $this->tura->update(['waiting_list_enabled' => true]);

        $this->join($this->gedeila, 'أحمد');
        $this->join($this->gedeila, 'منى');
        $turaEntry = $this->join($this->tura, 'سارة');

        $service = app(WaitingListService::class);
        $this->assertSame(2, $service->forDay($this->clinic, self::SATURDAY, $this->gedeila)->count());
        $this->assertSame(['سارة'], $service->forDay($this->clinic, self::SATURDAY, $this->tura)->pluck('patient.name')->all());
        $this->assertSame($this->tura->id, $turaEntry->branch_id);
        // The cap is counted per branch: Gedeila is full, Al-Tur'a is not.
        $this->assertTrue($service->isFull($this->clinic, self::SATURDAY, $this->gedeila));
        $this->assertFalse($service->isFull($this->clinic, self::SATURDAY, $this->tura));
        $this->assertSame(1, $service->position($turaEntry));
    }

    public function test_a_list_patient_is_booked_only_at_their_lists_branch(): void
    {
        $entry = $this->join($this->gedeila, 'أحمد');

        try {
            app(BookingService::class)->create($this->clinic, new BookingData(
                patientId: $entry->patient_id, patientName: null, phone: null, age: null, whatsappOptIn: false,
                visitTypeId: $this->clinic->visitTypes()->first()->id, date: self::SATURDAY, startTime: '17:00',
                bookingKind: BookingKind::NORMAL, patientLocation: null, source: BookingSource::CLINIC,
                waitingListEntryId: $entry->id,
            ), $this->owner, $this->tura);
            $this->fail('Booked from a list at another branch.');
        } catch (ApiException $e) {
            $this->assertSame('WAITING_LIST_WRONG_BRANCH', $e->errorCode->value);
        }
    }

    public function test_switching_a_list_off_with_people_on_it_asks_and_removes_nobody(): void
    {
        $entry = $this->join($this->gedeila, 'أحمد');
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.branches.waiting-list', $this->gedeila), ['enabled' => false])
            ->assertStatus(409)
            ->assertJsonPath('error.details.waiting_count', 1);

        $this->putJson(route('api.v1.branches.waiting-list', $this->gedeila), ['enabled' => false, 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.waiting_list_enabled', false);

        $this->assertSame(WaitingListStatus::WAITING, $entry->fresh()->status);

        // And nobody new can join.
        $this->expectException(ApiException::class);
        $this->join($this->gedeila, 'منى');
    }

    public function test_the_branches_screen_switches_a_list_and_asks_before_closing_one_in_use(): void
    {
        $this->join($this->gedeila, 'أحمد');

        Livewire::actingAs($this->owner)
            ->test(Branches::class)
            ->call('toggleWaitingList', $this->tura->id)
            ->assertSet('failed', false)
            ->call('toggleWaitingList', $this->gedeila->id)
            ->assertSet('confirmingListOff', $this->gedeila->id)
            ->assertSet('listOffWaiting', 1)
            ->call('confirmWaitingListOff')
            ->assertSet('confirmingListOff', null);

        $this->assertTrue($this->tura->fresh()->waiting_list_enabled);
        $this->assertFalse($this->gedeila->fresh()->waiting_list_enabled);
    }

    public function test_the_api_list_is_the_app_branchs_and_another_branchs_entry_is_out_of_reach(): void
    {
        $this->tura->update(['waiting_list_enabled' => true]);
        $gedeilaEntry = $this->join($this->gedeila, 'أحمد');
        $this->join($this->tura, 'سارة');
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();
        Sanctum::actingAs($member);

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->getJson(route('api.v1.waiting-list.index', ['date' => self::SATURDAY]))
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonCount(1, 'data.items');

        $this->deleteJson(route('api.v1.waiting-list.destroy', $gedeilaEntry))->assertNotFound();
    }

    public function test_each_branchs_list_closes_when_that_branchs_day_ends(): void
    {
        $this->tura->update(['waiting_list_enabled' => true]);
        $gedeila = $this->join($this->gedeila, 'أحمد');
        $tura = $this->join($this->tura, 'سارة');

        // 17:00: Gedeila closed at 16:00, Al-Tur'a is open until 20:00.
        Carbon::setTestNow(Carbon::parse(self::SATURDAY.' 17:00:00', 'Africa/Cairo'));
        app(WaitingListService::class)->closeEndedDays($this->clinic->fresh());

        $this->assertSame(WaitingListStatus::CLOSED, $gedeila->fresh()->status);
        $this->assertSame(WaitingListStatus::WAITING, $tura->fresh()->status);
    }

    public function test_existing_entries_and_entries_written_without_a_branch_land_on_the_first(): void
    {
        $entry = $this->join($this->gedeila, 'أحمد');
        DB::table('waiting_list_entries')->update(['branch_id' => null]);

        (require database_path('migrations/2026_11_30_100000_add_branch_to_waiting_lists.php'))->backfill();

        $this->assertSame($this->gedeila->id, $entry->fresh()->branch_id);
    }
}
