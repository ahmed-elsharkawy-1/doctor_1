<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\DayOfWeek;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Models\Branch;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Settings\ScheduleService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-18 on the patient's side: the branch first, then its days and times;
 * one active branch and nothing changes.
 */
class BranchSelfBookingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const SATURDAY = '2026-12-05';

    private Branch $dokki;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-03 10:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true, 'clinic.self_booking.require_otp' => false]);

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);
        $this->clinic->update(['slug' => 'dr-sara', 'self_booking_enabled' => true, 'patient_booking_window_days' => 7, 'address' => '١٢ شارع مصدق، الدقي']);
        $this->dokki = $this->clinic->firstBranch();
        $this->open($this->dokki, '13:00', '16:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function open(Branch $branch, string $from, string $to): void
    {
        app(ScheduleService::class)->updateDay($branch, ScheduleDayData::fromArray(DayOfWeek::SATURDAY, [
            'is_open' => true,
            'periods' => [['start_time' => $from, 'end_time' => $to]],
        ]));
    }

    private function gedeila(): Branch
    {
        $branch = app(BranchService::class)->create(
            $this->clinic,
            new BranchData('عيادة جديلة', '15 شارع الجمهورية، جديلة', 31.04, 31.37, null),
            $this->owner,
        );
        $this->open($branch, '17:00', '20:00');

        return $branch;
    }

    public function test_one_branch_shows_no_branch_choice(): void
    {
        Livewire::test(BookVisit::class, ['slug' => 'dr-sara'])
            ->assertDontSeeHtml('branch-card')
            ->assertSet('branchId', $this->dokki->id);
    }

    public function test_branch_cards_list_every_branch_a_patient_can_book_at(): void
    {
        $gedeila = $this->gedeila();
        app(BranchService::class)->create($this->clinic, new BranchData('عيادة بلا مواعيد', 'x', 30, 31, null), $this->owner);

        Livewire::test(BookVisit::class, ['slug' => 'dr-sara'])
            ->assertSeeHtml('branch-card')
            ->assertSee($gedeila->name)
            ->assertSee('15 شارع الجمهورية، جديلة')
            // No hours yet: not bookable, not shown.
            ->assertDontSee('عيادة بلا مواعيد');
    }

    public function test_a_branchs_own_link_opens_with_it_picked(): void
    {
        $gedeila = $this->gedeila();

        Livewire::withQueryParams(['b' => $gedeila->id])
            ->test(BookVisit::class, ['slug' => 'dr-sara'])
            ->assertSet('branchId', $gedeila->id)
            ->assertSet('date', self::SATURDAY);
    }

    public function test_a_made_up_branch_in_the_link_falls_back_to_the_first(): void
    {
        $this->gedeila();
        $foreign = $this->otherClinic()->firstBranch();

        Livewire::withQueryParams(['b' => $foreign->id])
            ->test(BookVisit::class, ['slug' => 'dr-sara'])
            ->assertSet('branchId', $this->dokki->id);
    }

    public function test_the_patient_books_at_the_branch_they_picked_and_is_told_where(): void
    {
        $gedeila = $this->gedeila();

        Livewire::test(BookVisit::class, ['slug' => 'dr-sara'])
            ->call('selectBranch', $gedeila->id)
            ->call('start')
            ->set('name', 'فاطمة')
            ->set('phone', '01012225521')
            ->call('sendCode')
            ->assertSet('branchId', $gedeila->id)
            ->call('selectDay', self::SATURDAY)
            ->call('selectSlot', '17:00')
            ->call('confirm')
            ->assertSet('failed', false)
            ->assertViewHas('stage', 'done')
            ->assertSee('عيادة جديلة')
            ->assertSee('15 شارع الجمهورية، جديلة');

        $this->assertSame($gedeila->id, Booking::query()->sole()->branch_id);
    }

    public function test_switching_branch_drops_the_time_picked_at_the_other(): void
    {
        $gedeila = $this->gedeila();

        Livewire::test(BookVisit::class, ['slug' => 'dr-sara'])
            ->call('start')
            ->set('name', 'فاطمة')
            ->set('phone', '01012225521')
            ->call('sendCode')
            ->call('selectDay', self::SATURDAY)
            ->call('selectSlot', '13:00')
            ->assertSet('startTime', '13:00')
            ->call('selectBranch', $gedeila->id)
            ->assertSet('startTime', null)
            ->assertSet('date', self::SATURDAY);
    }

    public function test_the_doctor_page_lists_branches_with_their_own_booking_link(): void
    {
        $gedeila = $this->gedeila();

        $this->get('/dr-sara')
            ->assertOk()
            ->assertSee('🏥 '.$gedeila->name)
            ->assertSee(route('patient.book', 'dr-sara').'?b='.$gedeila->id, false);
    }

    public function test_a_single_branch_doctor_page_is_unchanged(): void
    {
        $this->get('/dr-sara')
            ->assertOk()
            ->assertDontSee('?b=', false)
            ->assertSee('١٢ شارع مصدق، الدقي');
    }
}
