<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\ApiException;
use App\Livewire\App\BulkCancel;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Queue\BulkCancellationService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-22: cancelling days at one or more branches, with bookings unticked;
 * and no broadcast reaching a branch it wasn't sent for.
 */
class BranchBulkCancelTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Branch $gedeila;

    private Branch $tura;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-05 09:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);
        Queue::fake();

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);
        $this->gedeila = $this->clinic->firstBranch();
        $this->tura = app(BranchService::class)->create($this->clinic, new BranchData('عيادة الترعة', 'الترعة', 31.1, 31.4, null), $this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(Branch $branch, string $at): Booking
    {
        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse($at, 'Africa/Cairo'))
            ->create([
                'branch_id' => $branch->id,
                'patient_id' => Patient::factory()->create(['clinic_id' => $this->clinic->id])->id,
            ]);
    }

    public function test_it_cancels_the_chosen_branches_days_leaving_unticked_ones(): void
    {
        $g1 = $this->booking($this->gedeila, '2026-12-05 13:00');
        $g2 = $this->booking($this->gedeila, '2026-12-06 13:00');
        $kept = $this->booking($this->gedeila, '2026-12-06 14:00');
        $later = $this->booking($this->gedeila, '2026-12-08 13:00');
        $t1 = $this->booking($this->tura, '2026-12-05 17:00');

        $result = app(BulkCancellationService::class)->cancel(
            $this->clinic, $this->owner, ['2026-12-05', '2026-12-06'], [$this->gedeila->id], [$kept->id],
        );

        $this->assertSame(2, $result['cancelled_count']);
        $this->assertSame([$this->gedeila->id => 2], $result['by_branch']);

        foreach ([$g1, $g2] as $booking) {
            $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
            // A cancellation, never a no-show.
            $this->assertSame(CancelReason::EMERGENCY, $booking->fresh()->cancel_reason);
        }

        foreach ([$kept, $later, $t1] as $booking) {
            $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
        }
    }

    public function test_all_branches_is_all_of_hers(): void
    {
        $g1 = $this->booking($this->gedeila, '2026-12-05 13:00');
        $t1 = $this->booking($this->tura, '2026-12-05 17:00');
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();

        app(BulkCancellationService::class)->cancel($this->clinic, $member, ['2026-12-05'], null);

        $this->assertSame(BookingStatus::BOOKED, $g1->fresh()->status);
        $this->assertSame(BookingStatus::CANCELLED, $t1->fresh()->status);

        $this->expectException(ApiException::class);
        app(BulkCancellationService::class)->cancel($this->clinic, $member, ['2026-12-05'], [$this->gedeila->id]);
    }

    public function test_past_days_or_too_many_are_refused(): void
    {
        $this->booking($this->gedeila, '2026-12-05 13:00');
        $service = app(BulkCancellationService::class);

        try {
            $service->candidates($this->clinic, $this->owner, ['2026-12-04']);
            $this->fail('A past day was accepted.');
        } catch (ApiException $e) {
            $this->assertSame('BULK_CANCEL_DAYS_INVALID', $e->errorCode->value);
        }

        $this->expectException(ApiException::class);
        $service->candidates($this->clinic, $this->owner, array_map(
            fn ($i) => Carbon::parse('2026-12-05')->addDays($i)->toDateString(),
            range(0, 20),
        ));
    }

    public function test_the_api_groups_by_branch_and_cancels(): void
    {
        $this->booking($this->gedeila, '2026-12-05 13:00');
        $t1 = $this->booking($this->tura, '2026-12-05 17:00');
        Sanctum::actingAs($this->owner);

        $this->getJson(route('api.v1.bulk-cancellations.index', ['dates' => ['2026-12-05']]))
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(2, 'data.branches');

        $this->postJson(route('api.v1.bulk-cancellations.store'), ['dates' => ['2026-12-05'], 'branch_ids' => [$this->tura->id]])
            ->assertOk()
            ->assertJsonPath('data.cancelled_count', 1);

        $this->assertSame(BookingStatus::CANCELLED, $t1->fresh()->status);
    }

    public function test_a_day_cancellation_broadcast_reaches_only_its_branch(): void
    {
        $g1 = $this->booking($this->gedeila, '2026-12-05 13:00');
        $t1 = $this->booking($this->tura, '2026-12-05 17:00');
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->tura)->create();
        Sanctum::actingAs($member);

        // The whole day, and Gedeila's booking named outright.
        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->postJson(route('api.v1.broadcasts.store'), [
                'template_key' => WhatsAppMessagingService::CANCELS_THE_DAY_KEY,
                'booking_ids' => [$g1->id, $t1->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.cancelled_count', 1);

        $this->assertSame(BookingStatus::BOOKED, $g1->fresh()->status);
    }

    public function test_the_screen_shows_a_count_per_branch_and_cancels_on_one_confirmation(): void
    {
        $g1 = $this->booking($this->gedeila, '2026-12-05 13:00');
        $t1 = $this->booking($this->tura, '2026-12-05 17:00');
        $t2 = $this->booking($this->tura, '2026-12-05 17:30');

        Livewire::actingAs($this->owner)
            ->test(BulkCancel::class)
            ->call('allBranches')
            ->call('toggleExcluded', $t2->id)
            ->call('confirm')
            ->assertSee(__('app.bulk_cancel.summary', ['count' => 2]))
            ->call('cancel')
            ->assertSet('failed', false);

        $this->assertSame(BookingStatus::CANCELLED, $g1->fresh()->status);
        $this->assertSame(BookingStatus::CANCELLED, $t1->fresh()->status);
        $this->assertSame(BookingStatus::BOOKED, $t2->fresh()->status);
    }
}
