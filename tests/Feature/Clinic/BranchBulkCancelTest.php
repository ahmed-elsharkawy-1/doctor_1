<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\ApiException;
use App\Livewire\App\BulkCancel;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\User;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Queue\BulkCancellationService;
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
            $this->clinic, $this->owner, ['2026-12-05', '2026-12-06'], [$this->gedeila->id], [$g1->id, $g2->id],
        );

        $this->assertSame(2, $result['cancelled_count']);
        $this->assertSame([$this->gedeila->id => 2], $result['by_branch']);
        $this->assertSame(1, $result['unlisted_count']);

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

        app(BulkCancellationService::class)->cancel($this->clinic, $member, ['2026-12-05'], null, [$g1->id, $t1->id]);

        $this->assertSame(BookingStatus::BOOKED, $g1->fresh()->status);
        $this->assertSame(BookingStatus::CANCELLED, $t1->fresh()->status);

        $this->expectException(ApiException::class);
        app(BulkCancellationService::class)->cancel($this->clinic, $member, ['2026-12-05'], [$this->gedeila->id], [$g1->id]);
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
            ->assertUnprocessable();

        $this->postJson(route('api.v1.bulk-cancellations.store'), ['dates' => ['2026-12-05'], 'branch_ids' => [$this->tura->id], 'booking_ids' => [$t1->id]])
            ->assertOk()
            ->assertJsonPath('data.cancelled_count', 1)
            ->assertJsonPath('data.by_branch.'.$this->tura->id, 1)
            ->assertJsonMissingPath('data.cancelled_ids');

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

    public function test_a_booking_made_after_the_list_was_shown_is_left_as_it_is(): void
    {
        $shown = $this->booking($this->gedeila, '2026-12-05 13:00');

        $screen = Livewire::actingAs($this->owner)
            ->test(BulkCancel::class)
            ->call('confirm');

        // Booked online while the confirmation was open.
        $later = $this->booking($this->gedeila, '2026-12-05 14:00');

        $screen->call('cancel')
            ->assertSet('failed', false)
            ->assertSee(trans_choice('booking.bulk_cancel.unlisted', 1, ['count' => 1]));

        $this->assertSame(BookingStatus::CANCELLED, $shown->fresh()->status);
        $this->assertSame(BookingStatus::BOOKED, $later->fresh()->status);
    }

    public function test_a_second_cancel_sends_nothing_again(): void
    {
        $g1 = $this->booking($this->gedeila, '2026-12-05 13:00');
        $g1->patient->update(['whatsapp_opt_in_at' => now()]);
        $service = app(BulkCancellationService::class);

        $first = $service->cancel($this->clinic, $this->owner, ['2026-12-05'], null, [$g1->id]);
        $this->assertSame(1, $first['sent_count']);

        try {
            $service->cancel($this->clinic, $this->owner, ['2026-12-05'], null, [$g1->id]);
            $this->fail('Cancelled twice.');
        } catch (ApiException $e) {
            $this->assertSame('NOTHING_TO_CANCEL', $e->errorCode->value);
        }

        $this->assertSame(1, OutboundMessage::query()->where('booking_id', $g1->id)->count());
    }

    public function test_a_booking_cancelled_meanwhile_is_neither_counted_nor_told_again(): void
    {
        $g1 = $this->booking($this->gedeila, '2026-12-05 13:00');
        $g2 = $this->booking($this->gedeila, '2026-12-05 14:00');
        $g1->patient->update(['whatsapp_opt_in_at' => now()]);

        // The first request's candidates were read; the booking is
        // cancelled by another before this one takes the lock.
        $result = app(WhatsAppMessagingService::class)->broadcast(
            $this->clinic, WhatsAppMessagingService::CANCELS_THE_DAY_KEY, bookingIds: [$g1->id], branchIds: [$this->gedeila->id],
        );
        $this->assertSame([$g1->id], $result['cancelled_ids']);

        $again = app(WhatsAppMessagingService::class)->broadcast(
            $this->clinic, WhatsAppMessagingService::CANCELS_THE_DAY_KEY, bookingIds: [$g1->id, $g2->id], branchIds: [$this->gedeila->id],
        );

        $this->assertSame(1, $again['cancelled_count']);
        $this->assertSame([$g2->id], $again['cancelled_ids']);
        $this->assertSame(1, OutboundMessage::query()->where('booking_id', $g1->id)->count());
    }

    public function test_earlier_today_a_passed_booking_is_a_no_show_and_one_waiting_is_listed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-05 15:00:00', 'Africa/Cairo'));
        $passed = $this->booking($this->gedeila, '2026-12-05 09:00');
        $waiting = $this->booking($this->gedeila, '2026-12-05 10:00');
        $waiting->update(['status' => BookingStatus::ARRIVED]);
        $toCome = $this->booking($this->gedeila, '2026-12-05 16:00');

        $ids = app(BulkCancellationService::class)
            ->candidates($this->clinic, $this->owner, ['2026-12-05'])
            ->flatten(1)->pluck('id')->sort()->values()->all();

        $this->assertSame([$waiting->id, $toCome->id], $ids);
        $this->assertNotContains($passed->id, $ids);
    }

    public function test_a_date_error_clears_once_the_days_are_fixed_and_to_follows_from(): void
    {
        Livewire::actingAs($this->owner)
            ->test(BulkCancel::class)
            ->set('to', '2026-12-31')
            ->assertSet('dateError', __('booking.bulk_cancel.days_invalid', ['days' => 14]))
            ->set('to', '2026-12-06')
            ->assertSet('dateError', null)
            ->set('from', '2026-12-10')
            ->assertSet('to', '2026-12-10')
            ->assertSet('dateError', null);
    }

    public function test_a_booking_left_without_a_branch_is_the_first_branchs(): void
    {
        $g1 = $this->booking($this->gedeila, '2026-12-05 13:00');
        DB::table('bookings')->where('id', $g1->id)->update(['branch_id' => null]);

        $result = app(WhatsAppMessagingService::class)->broadcast(
            $this->clinic, WhatsAppMessagingService::CANCELS_THE_DAY_KEY, Carbon::parse('2026-12-05'),
        );

        $this->assertSame(1, $result['cancelled_count']);
    }

    public function test_a_double_tap_cancels_once_and_the_unticked_stay_kept(): void
    {
        $g1 = $this->booking($this->gedeila, '2026-12-05 13:00');
        $kept = $this->booking($this->gedeila, '2026-12-05 14:00');

        Livewire::actingAs($this->owner)
            ->test(BulkCancel::class)
            ->call('toggleExcluded', $kept->id)
            ->call('confirm')
            ->call('cancel')
            // The second tap of a double tap, in the same request.
            ->call('cancel')
            ->assertSet('excluded', [(string) $kept->id])
            ->assertSee(trans_choice('booking.bulk_cancel.cancelled', 1, ['count' => 1]));

        $this->assertSame(BookingStatus::CANCELLED, $g1->fresh()->status);
        $this->assertSame(BookingStatus::BOOKED, $kept->fresh()->status);
    }

    public function test_a_past_day_says_so(): void
    {
        Livewire::actingAs($this->owner)
            ->test(BulkCancel::class)
            ->set('from', '2026-12-01')
            ->assertSet('dateError', __('booking.bulk_cancel.days_past'));
    }
}
