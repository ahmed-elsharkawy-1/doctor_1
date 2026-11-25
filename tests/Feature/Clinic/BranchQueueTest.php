<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Livewire\App\PatientProfile;
use App\Livewire\App\Queue;
use App\Livewire\App\Settings\Displays;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\QueueDisplay;
use App\Models\User;
use App\Services\V1\Branches\ActiveBranch;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Displays\QueueDisplayService;
use App\Services\V1\Patients\OnlineBookingService;
use App\Services\V1\Queue\DelayService;
use App\Services\V1\Queue\QueuePositionService;
use App\Services\V1\Settings\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-19: each branch runs its own queue and TV; a patient keeps one record
 * across branches. Saturday 2026-12-05: Gedeila 13:00–16:00, Al-Tur'a
 * 17:00–20:00.
 */
class BranchQueueTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const SATURDAY = '2026-12-05';

    private Branch $gedeila;

    private Branch $tura;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::SATURDAY.' 14:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);

        $this->setUpClinic();
        $this->gedeila = $this->clinic->firstBranch();
        $this->gedeila->update(['name' => 'عيادة جديلة']);
        $this->tura = app(BranchService::class)->create($this->clinic, new BranchData('عيادة الترعة', '5 شارع الترعة', 31.1, 31.4, null), $this->owner);

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

    private function booking(Branch $branch, string $time, string $name, BookingStatus $status = BookingStatus::BOOKED, ?Patient $patient = null): Booking
    {
        $patient ??= Patient::factory()->create(['clinic_id' => $this->clinic->id, 'name' => $name]);

        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse(self::SATURDAY.' '.$time, 'Africa/Cairo'))
            ->create(['branch_id' => $branch->id, 'patient_id' => $patient->id, 'status' => $status]);
    }

    public function test_a_patients_place_counts_only_their_own_branchs_queue(): void
    {
        $this->booking($this->gedeila, '13:00', 'أحمد');
        $this->booking($this->gedeila, '13:20', 'منى');
        $first = $this->booking($this->tura, '17:00', 'سارة');

        $position = app(QueuePositionService::class)->for($first);

        $this->assertSame(0, $position->ahead);
        $this->assertSame(1, $position->total);
    }

    public function test_the_queue_screen_shows_only_the_branch_it_is_on(): void
    {
        $this->booking($this->gedeila, '13:00', 'أحمد جديلة');
        $this->booking($this->tura, '17:00', 'سارة الترعة');

        session([ActiveBranch::SESSION_KEY => $this->tura->id]);

        Livewire::actingAs($this->owner)
            ->test(Queue::class)
            ->assertSee('سارة الترعة')
            ->assertDontSee('أحمد جديلة');
    }

    public function test_the_api_search_and_call_list_follow_the_branch(): void
    {
        $this->booking($this->gedeila, '13:00', 'مريم');
        $this->booking($this->tura, '17:00', 'مريم');
        $this->booking($this->tura, '17:20', 'هدى', BookingStatus::CANCELLED)->update(['cancel_reason' => 'emergency']);
        Sanctum::actingAs($this->owner);

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->getJson(route('api.v1.queue.search', ['q' => 'مريم']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.total', 1);

        // The call list: Al-Tur'a's cancelled patient, not Gedeila's day.
        $this->withHeader('X-Branch-Id', (string) $this->gedeila->id)
            ->getJson(route('api.v1.rebooking-list'))
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_a_delay_moves_only_the_delayed_patients_branch(): void
    {
        Carbon::setTestNow(Carbon::parse(self::SATURDAY.' 12:00:00', 'Africa/Cairo'));
        $first = $this->booking($this->gedeila, '13:00', 'أحمد');
        $gedeilaLater = $this->booking($this->gedeila, '13:20', 'منى');
        $tura = $this->booking($this->tura, '17:00', 'سارة');

        app(DelayService::class)->delay($this->clinic, $first->id, 30);

        $this->assertSame('13:50', $gedeilaLater->fresh()->start_at->format('H:i'));
        $this->assertSame('17:00', $tura->fresh()->start_at->format('H:i'));
    }

    public function test_the_tracking_page_names_the_branch(): void
    {
        $booking = $this->booking($this->tura, '17:00', 'سارة');

        $this->get($booking->trackingUrl())
            ->assertOk()
            ->assertSee('🏥 عيادة الترعة')
            ->assertSee('5 شارع الترعة');
    }

    public function test_one_record_per_patient_with_every_visit_tagged_by_its_branch(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'منى']);
        $this->booking($this->gedeila, '13:00', 'x', BookingStatus::DONE, $patient);
        $this->booking($this->tura, '17:00', 'x', BookingStatus::BOOKED, $patient);
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();

        // Any secretary of the doctor sees the full history.
        Livewire::actingAs($member)
            ->test(PatientProfile::class, ['patient' => $patient->id])
            ->assertSee('🏥 عيادة جديلة')
            ->assertSee('🏥 عيادة الترعة');

        Sanctum::actingAs($member);
        $this->getJson(route('api.v1.patients.show', $patient))
            ->assertOk()
            ->assertJsonFragment(['name' => 'عيادة جديلة']);
    }

    public function test_no_shows_count_across_branches(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
        $this->booking($this->gedeila, '13:00', 'x', BookingStatus::NO_SHOW, $patient);
        $this->booking($this->tura, '17:00', 'x', BookingStatus::NO_SHOW, $patient);

        $this->assertSame(2, app(OnlineBookingService::class)->countedNoShows($patient));
    }

    public function test_a_tv_shows_only_its_branchs_queue_by_code_and_first_name(): void
    {
        $this->booking($this->gedeila, '13:00', 'أحمد علي', BookingStatus::WITH_DOCTOR);
        $this->booking($this->gedeila, '13:20', 'منى حسن', BookingStatus::ARRIVED);
        $this->booking($this->tura, '17:00', 'سارة محمد');
        $display = app(QueueDisplayService::class)->create($this->clinic, $this->owner, $this->gedeila->id, 'الصالة');

        $this->get(route('tv.show', $display->token))
            ->assertOk()
            ->assertSee('🏥 عيادة جديلة')
            ->assertSee('أحمد')
            ->assertSee('منى')
            ->assertDontSee('علي')
            ->assertDontSee('سارة');

        $this->assertNotNull($display->fresh()->last_seen_at);
    }

    public function test_a_tv_rests_outside_its_branchs_hours(): void
    {
        $display = app(QueueDisplayService::class)->create($this->clinic, $this->owner, $this->tura->id, 'الصالة');

        // 14:00 at Al-Tur'a, which opens at 17:00, with nobody waiting.
        $this->get(route('tv.show', $display->token))
            ->assertOk()
            ->assertSee(__('displays.idle_title'));
    }

    public function test_a_new_link_retires_the_old_one(): void
    {
        $display = app(QueueDisplayService::class)->create($this->clinic, $this->owner, $this->tura->id, 'الصالة');
        $old = $display->token;

        app(QueueDisplayService::class)->rotate($this->clinic, $this->owner, $display->id);

        $this->get(route('tv.show', $old))->assertNotFound();
        $this->get(route('tv.show', $display->fresh()->token))->assertOk();
    }

    public function test_moving_a_tv_needs_manage_branches_within_ones_branches(): void
    {
        $display = app(QueueDisplayService::class)->create($this->clinic, $this->owner, $this->gedeila->id, 'شاشة جديلة');

        Livewire::actingAs(User::factory()->secretary([])->inClinic($this->clinic)->create())
            ->test(Displays::class)
            ->assertForbidden();

        $manager = User::factory()->secretary(['branches.manage'])->inClinic($this->clinic)->atBranches($this->tura)->create();

        // Her branch only: the Gedeila TV isn't hers to see or move.
        Livewire::actingAs($manager)
            ->test(Displays::class)
            ->assertDontSee('شاشة جديلة')
            ->set('name', 'شاشة الترعة')
            ->call('add')
            ->assertSet('failed', false);

        $this->assertSame($this->tura->id, QueueDisplay::query()->where('name', 'شاشة الترعة')->value('branch_id'));
        $this->assertSame($this->gedeila->id, $display->fresh()->branch_id);
    }
}
