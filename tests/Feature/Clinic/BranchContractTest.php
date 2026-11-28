<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\Models\Booking;
use App\Models\Branch;
use App\Services\V1\Branches\BranchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Phase 8, the contract: a booking, a hold, a waiting-list entry and a week
 * of hours are always at a branch, and a branch with history is never
 * deleted from under it.
 */
class BranchContractTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Branch $tura;

    protected function setUp(): void
    {
        parent::setUp();

        config(['clinic.branches.multi_enabled' => true]);
        $this->setUpClinic();
        $this->tura = app(BranchService::class)->create($this->clinic, new BranchData('عيادة الترعة', 'الترعة', 31.1, 31.4, null), $this->owner);
    }

    public function test_a_booking_written_without_a_branch_is_the_first_branchs(): void
    {
        $booking = Booking::factory()->forClinic($this->clinic)->create();

        $this->assertSame($this->clinic->firstBranchId(), $booking->branch_id);
    }

    public function test_the_database_refuses_a_row_without_a_branch(): void
    {
        $booking = Booking::factory()->forClinic($this->clinic)->create();

        $this->expectException(QueryException::class);
        DB::table('bookings')->where('id', $booking->id)->update(['branch_id' => null]);
    }

    public function test_a_branch_with_bookings_cannot_be_deleted_but_its_hours_go_with_it(): void
    {
        $this->assertSame(7, $this->tura->schedules()->count());

        $empty = app(BranchService::class)->create($this->clinic, new BranchData('عيادة المنصورة', 'المنصورة', 31.0, 31.3, null), $this->owner);
        $empty->delete();
        $this->assertSame(0, DB::table('clinic_schedules')->where('branch_id', $empty->id)->count());

        Booking::factory()->forClinic($this->clinic)->create(['branch_id' => $this->tura->id]);

        $this->expectException(QueryException::class);
        $this->tura->delete();
    }
}
