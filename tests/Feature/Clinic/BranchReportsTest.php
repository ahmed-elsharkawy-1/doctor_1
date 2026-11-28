<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\Enums\ReviewRating;
use App\Livewire\App\Reports;
use App\Livewire\App\Reviews;
use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\RetentionService;
use App\Services\Reports\RevenueService;
use App\Services\V1\Branches\BranchScope;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Reports\ReportAccess;
use App\Services\V1\Reports\ReportDeliveryService;
use App\Services\V1\Reviews\ClinicReviewsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Phase 8: the reports and the reviews read "all branches" by default, or one
 * of them; a team member reads her own branches only.
 */
class BranchReportsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Branch $gedeila;

    private Branch $tura;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-15 12:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);

        $this->setUpClinic();
        $this->gedeila = $this->clinic->firstBranch();
        $this->tura = app(BranchService::class)->create($this->clinic, new BranchData('عيادة الترعة', 'الترعة', 31.1, 31.4, null), $this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function visit(Branch $branch, string $at, float $price = 200, ?Patient $patient = null): Booking
    {
        $patient ??= Patient::factory()->create(['clinic_id' => $this->clinic->id]);

        return Booking::factory()->forClinic($this->clinic)->done()
            ->at(Carbon::parse($at, 'Africa/Cairo'))
            ->create(['branch_id' => $branch->id, 'patient_id' => $patient->id, 'price' => $price]);
    }

    private function review(Booking $booking, ReviewRating $rating): BookingReview
    {
        return BookingReview::create([
            'booking_id' => $booking->id,
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $booking->doctor_id,
            'patient_id' => $booking->patient_id,
            'rating' => $rating,
            'submitted_at' => now(),
        ]);
    }

    private function monthTotal(?array $scope): float
    {
        return app(RevenueService::class)->forPeriod($this->clinic, ReportPeriod::thisMonth($this->clinic), $scope)['total'];
    }

    public function test_revenue_is_all_branches_or_one(): void
    {
        $this->visit($this->gedeila, '2026-12-10 13:00', 200);
        $this->visit($this->tura, '2026-12-11 17:00', 350);

        $this->assertSame(550.0, $this->monthTotal(null));
        $this->assertSame(200.0, $this->monthTotal([$this->gedeila->id]));
        $this->assertSame(350.0, $this->monthTotal([$this->tura->id]));
    }

    public function test_an_owners_all_branches_is_the_whole_clinic_and_a_members_is_hers(): void
    {
        $scope = app(BranchScope::class);
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();

        $this->assertNull($scope->resolve($this->owner, $this->clinic));
        $this->assertSame([$this->tura->id], $scope->resolve($member, $this->clinic));
        $this->assertSame([$this->gedeila->id], $scope->resolve($this->owner, $this->clinic, $this->gedeila->id));
    }

    public function test_retention_counts_where_a_patient_first_came_and_any_return(): void
    {
        // First seen at Gedeila, back at Al-Tur'a: Gedeila's, and kept.
        $moved = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
        $this->visit($this->gedeila, '2026-12-02 13:00', patient: $moved);
        $this->visit($this->tura, '2026-12-09 17:00', patient: $moved);
        // First seen at Al-Tur'a.
        $this->visit($this->tura, '2026-12-03 17:00');

        $period = ReportPeriod::thisMonth($this->clinic);
        $gedeila = app(RetentionService::class)->forPeriod($this->clinic, $period, [$this->gedeila->id]);
        $tura = app(RetentionService::class)->forPeriod($this->clinic, $period, [$this->tura->id]);
        $all = app(RetentionService::class)->forPeriod($this->clinic, $period);

        $this->assertSame([1, 1, 1], [$gedeila['cohort_size'], $gedeila['returned_count'], $gedeila['visits_in_period']]);
        $this->assertSame([1, 0, 2], [$tura['cohort_size'], $tura['returned_count'], $tura['visits_in_period']]);
        $this->assertSame([2, 1, 3], [$all['cohort_size'], $all['returned_count'], $all['visits_in_period']]);
    }

    public function test_reviews_are_of_the_visits_branch(): void
    {
        $this->review($this->visit($this->gedeila, '2026-12-10 13:00'), ReviewRating::VERY_GOOD);
        $this->review($this->visit($this->tura, '2026-12-11 17:00'), ReviewRating::BAD);
        $service = app(ClinicReviewsService::class);

        $this->assertSame(2, $service->summary($this->clinic)->total());
        $this->assertSame(1, $service->summary($this->clinic, [$this->tura->id])->count(ReviewRating::BAD));
        $this->assertSame(0, $service->summary($this->clinic, [$this->tura->id])->count(ReviewRating::VERY_GOOD));
        $this->assertSame(1, $service->list($this->clinic, null, 20, 1, [$this->gedeila->id])->total());
    }

    public function test_the_api_reads_one_branch_and_refuses_one_not_hers(): void
    {
        $this->visit($this->gedeila, '2026-12-10 13:00', 200);
        $this->visit($this->tura, '2026-12-11 17:00', 350);
        Sanctum::actingAs($this->owner);

        $this->getJson(route('api.v1.reports.revenue', ['branch_id' => $this->tura->id]))
            ->assertOk()
            ->assertJsonPath('data.periods.this_month.completed_visits', 1);
        $this->getJson(route('api.v1.reports.revenue'))
            ->assertOk()
            ->assertJsonPath('data.periods.this_month.completed_visits', 2);

        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();
        Sanctum::actingAs($member);

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->getJson(route('api.v1.reviews.index', ['branch_id' => $this->gedeila->id]))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'BRANCH_NOT_FOUND');
    }

    public function test_the_screens_offer_the_branches_and_read_the_one_chosen(): void
    {
        $this->review($this->visit($this->tura, '2026-12-11 17:00'), ReviewRating::BAD);

        Livewire::actingAs($this->owner)
            ->test(Reviews::class)
            ->assertSee('عيادة الترعة')
            ->call('chooseBranch', (string) $this->gedeila->id)
            ->assertSet('reportBranch', (string) $this->gedeila->id)
            ->assertViewHas('summary', fn (array $summary) => $summary['total'] === 0)
            ->call('chooseBranch', '')
            ->assertViewHas('summary', fn (array $summary) => $summary['total'] === 1);

        Livewire::actingAs($this->owner)
            ->test(Reports::class)
            ->assertSee('عيادة الترعة');
    }

    public function test_a_branch_from_the_address_bar_she_cannot_read_falls_back_to_all(): void
    {
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();

        Livewire::withQueryParams(['branch' => (string) $this->gedeila->id])
            ->actingAs($member)
            ->test(Reviews::class)
            ->assertSet('reportBranch', '');
    }

    public function test_one_branch_or_multi_branch_off_offers_no_choice(): void
    {
        $this->tura->delete();

        Livewire::actingAs($this->owner)
            ->test(Reports::class)
            ->assertViewHas('branchChoices', fn ($choices) => $choices->isEmpty());

        config(['clinic.branches.multi_enabled' => false]);

        $this->assertTrue(app(BranchScope::class)->choices($this->owner, $this->clinic->fresh())->isEmpty());
    }

    public function test_a_secretary_in_a_one_branch_clinic_reads_the_whole_clinic(): void
    {
        $this->tura->delete();
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->gedeila)->create();

        $this->assertNull(app(BranchScope::class)->resolve($member, $this->clinic->fresh()));
    }

    public function test_a_member_at_two_of_three_branches_reads_those_two(): void
    {
        $third = app(BranchService::class)->create($this->clinic, new BranchData('عيادة المنصورة', 'المنصورة', 31.0, 31.3, null), $this->owner);
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->gedeila, $this->tura)->create();

        $scope = app(BranchScope::class)->resolve($member, $this->clinic);
        sort($scope);

        $this->assertSame([$this->gedeila->id, $this->tura->id], $scope);
        $this->assertNotContains($third->id, $scope);
    }

    public function test_the_first_visit_that_day_is_the_timed_one(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
        // An untimed visit at Gedeila, and a timed one at Al-Tur'a, the same day.
        $this->visit($this->gedeila, '2026-12-02 19:00', patient: $patient)->update(['start_at' => null, 'end_at' => null]);
        $this->visit($this->tura, '2026-12-02 10:00', patient: $patient);

        $period = ReportPeriod::thisMonth($this->clinic);

        $this->assertSame(1, app(RetentionService::class)->forPeriod($this->clinic, $period, [$this->tura->id])['cohort_size']);
        $this->assertSame(0, app(RetentionService::class)->forPeriod($this->clinic, $period, [$this->gedeila->id])['cohort_size']);
    }

    public function test_the_retention_api_reads_one_branch(): void
    {
        $this->visit($this->gedeila, '2026-12-02 13:00');
        $this->visit($this->tura, '2026-12-03 17:00');
        Sanctum::actingAs($this->owner);

        $this->getJson(route('api.v1.reports.retention', ['period' => 'this_month', 'branch_id' => $this->tura->id]))
            ->assertOk()
            ->assertJsonPath('data.cohort_size', 1)
            ->assertJsonPath('data.visits_in_period', 1);

        $this->getJson(route('api.v1.reports.retention', ['branch_id' => 0]))->assertUnprocessable();
    }

    public function test_the_doctors_whole_clinic_report_is_not_for_one_kept_to_some_branches(): void
    {
        $this->clinic->update(['reports_enabled' => true]);
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create(['can_access_reports' => true]);
        $everywhere = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->gedeila, $this->tura)->create(['can_access_reports' => true]);

        $access = app(ReportAccess::class);
        $this->assertNull($access->clinicFor($member));

        $recipients = app(ReportDeliveryService::class)->recipients($this->clinic)->pluck('id');
        $this->assertNotContains($member->id, $recipients);
        $this->assertContains($everywhere->id, $recipients);
    }

    public function test_a_branch_that_is_not_a_number_is_all_branches(): void
    {
        Livewire::withQueryParams(['branch' => 'abc'])
            ->actingAs($this->owner)
            ->test(Reviews::class)
            ->assertSet('reportBranch', '');
    }
}
