<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Booking\BookingData;
use App\DTOs\V1\Branches\BranchData;
use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\BookingKind;
use App\Enums\BookingSource;
use App\Enums\DayOfWeek;
use App\Enums\PatientLocation;
use App\Exceptions\ApiException;
use App\Livewire\App\NewBooking;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\MessageTemplate;
use App\Models\SlotHold;
use App\Models\User;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Messaging\TemplatePayloadResolver;
use App\Services\V1\Settings\HolidayService;
use App\Services\V1\Settings\ScheduleService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-18: a booking is at a branch; that branch's hours and holidays decide
 * its slots. Saturday 2026-12-05: Gedeila 13:00–16:00, Al-Tur'a 17:00–20:00.
 */
class BranchBookingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Branch $gedeila;

    private Branch $tura;

    private const SATURDAY = '2026-12-05';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);

        $this->setUpClinic();
        $this->clinic->visitTypes()->update(['price' => 300]);
        $this->gedeila = $this->clinic->firstBranch();
        $this->tura = app(BranchService::class)->create($this->clinic, new BranchData('عيادة الترعة', 'الترعة', 31.1, 31.4, null), $this->owner);

        $this->open($this->gedeila, [['13:00', '16:00']]);
        $this->open($this->tura, [['17:00', '20:00']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function open(Branch $branch, array $periods): void
    {
        app(ScheduleService::class)->updateDay($branch, ScheduleDayData::fromArray(DayOfWeek::SATURDAY, [
            'is_open' => true,
            'periods' => array_map(fn ($p) => ['start_time' => $p[0], 'end_time' => $p[1]], $periods),
        ]));
    }

    /** @return list<string> */
    private function slotTimes(Branch $branch): array
    {
        $visitType = $this->clinic->visitTypes()->first();

        return collect(app(SlotAvailabilityService::class)
            ->for($this->clinic, Carbon::parse(self::SATURDAY, 'Africa/Cairo'), $visitType, branch: $branch)->slots)
            ->map(fn ($slot) => $slot->startAt->format('H:i'))
            ->all();
    }

    private function book(Branch $branch, string $time, BookingKind $kind = BookingKind::NORMAL): Booking
    {
        return app(BookingService::class)->create($this->clinic, new BookingData(
            patientId: null,
            patientName: 'مريضة '.$time,
            phone: '010'.random_int(10000000, 99999999),
            age: 30,
            whatsappOptIn: false,
            visitTypeId: $this->clinic->visitTypes()->first()->id,
            date: self::SATURDAY,
            startTime: $kind === BookingKind::NORMAL ? $time : null,
            bookingKind: $kind,
            patientLocation: $kind === BookingKind::EMERGENCY ? PatientLocation::ON_WAY : null,
            source: BookingSource::CLINIC,
        ), $this->owner, $branch);
    }

    public function test_each_branch_offers_only_its_own_hours(): void
    {
        $gedeila = $this->slotTimes($this->gedeila);
        $tura = $this->slotTimes($this->tura);

        $this->assertSame('13:00', $gedeila[0]);
        $this->assertTrue(collect($gedeila)->every(fn ($t) => $t < '16:00'));
        $this->assertSame('17:00', $tura[0]);
        $this->assertTrue(collect($tura)->every(fn ($t) => $t >= '17:00'));
    }

    public function test_a_holiday_for_one_branch_closes_only_that_one(): void
    {
        app(HolidayService::class)->create($this->clinic, self::SATURDAY, null, branchIds: [$this->tura->id], actor: $this->owner);

        $this->assertNotEmpty($this->slotTimes($this->gedeila));
        $this->assertSame([], $this->slotTimes($this->tura));
    }

    public function test_every_booking_is_saved_at_its_branch(): void
    {
        $booking = $this->book($this->tura, '17:00');

        $this->assertSame($this->tura->id, $booking->branch_id);
        $this->assertSame(1, Booking::query()->where('branch_id', $this->tura->id)->count());
    }

    public function test_a_time_outside_the_branchs_hours_is_refused(): void
    {
        $this->expectException(ApiException::class);

        // 14:00 is Gedeila's, not Al-Tur'a's.
        $this->book($this->tura, '14:00');
    }

    public function test_a_deactivated_branch_takes_no_new_booking_not_even_an_emergency(): void
    {
        app(BranchService::class)->deactivate($this->tura);
        $this->tura->refresh();

        $this->assertSame([], $this->slotTimes($this->tura));

        $this->expectException(ApiException::class);
        $this->book($this->tura, '', BookingKind::EMERGENCY);
    }

    public function test_bookings_from_before_branches_and_new_rows_without_one_land_on_the_first_branch(): void
    {
        $booking = Booking::factory()->forClinic($this->clinic)->create();
        $this->assertSame($this->gedeila->id, $booking->branch_id);

        DB::table('bookings')->update(['branch_id' => null]);
        (require database_path('migrations/2026_11_28_100000_add_branch_id_to_bookings_and_slot_holds.php'))->backfill('bookings');

        $this->assertSame($this->gedeila->id, $booking->fresh()->branch_id);
    }

    public function test_the_api_books_and_holds_at_the_branch_the_app_is_on(): void
    {
        Sanctum::actingAs($this->owner);
        $visitType = $this->clinic->visitTypes()->first()->id;

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->getJson(route('api.v1.slots', ['date' => self::SATURDAY, 'visit_type_id' => $visitType]))
            ->assertOk()
            ->assertJsonPath('data.slots.0.start_time.value', '17:00');

        $token = $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->postJson(route('api.v1.slot-holds.store'), ['visit_type_id' => $visitType, 'date' => self::SATURDAY, 'start_time' => '17:00'])
            ->assertCreated()
            ->json('data.token');
        $this->assertSame($this->tura->id, SlotHold::query()->where('token', $token)->value('branch_id'));

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->postJson(route('api.v1.bookings.store'), [
                'patient_name' => 'سارة', 'phone' => '01012345678', 'visit_type_id' => $visitType,
                'date' => self::SATURDAY, 'start_time' => '17:00', 'booking_kind' => 'normal', 'hold_token' => $token,
            ])
            ->assertCreated();

        $this->assertSame(1, Booking::query()->where('branch_id', $this->tura->id)->count());
    }

    public function test_the_calendar_shows_only_the_branchs_bookings(): void
    {
        $this->book($this->gedeila, '13:00');
        $this->book($this->tura, '17:00');
        Sanctum::actingAs($this->owner);

        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->getJson(route('api.v1.bookings.calendar', ['from' => self::SATURDAY, 'to' => self::SATURDAY]))
            ->assertOk()
            ->assertJsonPath('data.days.0.counts.total', 1);
    }

    public function test_the_staff_screen_books_at_the_branch_picked_on_it(): void
    {
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->gedeila, $this->tura)->create();

        Livewire::actingAs($member)
            ->test(NewBooking::class)
            ->assertSee('عيادة الترعة')
            ->call('selectBranch', $this->tura->id)
            ->set('patientName', 'منى')
            ->set('phone', '01012345678')
            ->call('selectDay', self::SATURDAY)
            ->call('selectSlot', '17:00')
            ->call('save')
            ->assertSet('failed', false)
            ->assertSee('🏥 عيادة الترعة');

        $this->assertSame($this->tura->id, Booking::query()->sole()->branch_id);
    }

    public function test_the_staff_screen_offers_only_the_members_branches_with_hours(): void
    {
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->gedeila)->create();
        $closed = app(BranchService::class)->create($this->clinic, new BranchData('عيادة مقفولة', 'x', 30, 31, null), $this->owner);

        Livewire::actingAs($this->owner)->test(NewBooking::class)->assertDontSee('عيادة مقفولة');

        Livewire::actingAs($member)
            ->test(NewBooking::class)
            ->call('selectBranch', $this->tura->id)
            ->assertSet('branchId', $this->gedeila->id);

        $this->assertFalse($closed->hasWorkingHours());
    }

    public function test_the_clinic_day_follows_last_nights_evening_at_whichever_branch(): void
    {
        // Friday evening at Al-Tur'a runs to 02:00 Saturday.
        app(ScheduleService::class)->updateDay($this->tura, ScheduleDayData::fromArray(DayOfWeek::FRIDAY, [
            'is_open' => true,
            'periods' => [['start_time' => '21:00', 'end_time' => '02:00']],
        ]));

        Carbon::setTestNow(Carbon::parse('2026-12-05 01:00:00', 'Africa/Cairo'));

        $this->assertSame('2026-12-04', $this->clinic->fresh()->currentDay()->toDateString());
    }

    public function test_a_booking_at_a_deactivated_branch_can_still_be_edited_in_place(): void
    {
        $booking = $this->book($this->tura, '17:00');
        app(BranchService::class)->deactivate($this->tura);
        $data = fn (string $time, string $notes) => new BookingData(
            patientId: $booking->patient_id, patientName: null, phone: null, age: null, whatsappOptIn: false,
            visitTypeId: $booking->visit_type_id, date: self::SATURDAY, startTime: $time,
            bookingKind: BookingKind::NORMAL, patientLocation: null, source: BookingSource::CLINIC, notes: $notes,
        );

        // Same slot, new notes: carries on as usual (ELA-16).
        $updated = app(BookingService::class)->update($this->clinic, $booking->id, $data('17:00', 'بعد الأكل'));
        $this->assertSame('بعد الأكل', $updated->notes);

        // A new time there is a new booking there, which it no longer takes.
        $this->expectException(ApiException::class);
        app(BookingService::class)->update($this->clinic, $booking->id, $data('17:30', 'x'));
    }

    public function test_a_team_member_reaches_only_her_branches_bookings_by_id(): void
    {
        $turaBooking = $this->book($this->tura, '17:00');
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->gedeila)->create();

        Sanctum::actingAs($member);
        $this->getJson(route('api.v1.bookings.show', $turaBooking))->assertNotFound();

        Sanctum::actingAs($this->owner);
        $this->getJson(route('api.v1.bookings.show', $turaBooking))->assertOk();
    }

    public function test_a_hold_taken_at_one_branch_does_not_book_a_time_at_another(): void
    {
        Sanctum::actingAs($this->owner);
        $visitType = $this->clinic->visitTypes()->first()->id;

        $token = $this->withHeader('X-Branch-Id', (string) $this->gedeila->id)
            ->postJson(route('api.v1.slot-holds.store'), ['visit_type_id' => $visitType, 'date' => self::SATURDAY, 'start_time' => '13:00'])
            ->json('data.token');

        // 13:00 is Gedeila's: at Al-Tur'a it is outside the hours.
        $this->withHeader('X-Branch-Id', (string) $this->tura->id)
            ->postJson(route('api.v1.bookings.store'), [
                'patient_name' => 'سارة', 'phone' => '01012345678', 'visit_type_id' => $visitType,
                'date' => self::SATURDAY, 'start_time' => '13:00', 'booking_kind' => 'normal', 'hold_token' => $token,
            ])
            ->assertStatus(409);

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_switching_branch_on_the_staff_screen_moves_to_a_day_it_is_open(): void
    {
        Livewire::actingAs($this->owner)
            ->test(NewBooking::class)
            ->call('selectDay', '2026-12-01')
            ->call('selectBranch', $this->tura->id)
            ->assertSet('date', self::SATURDAY);
    }

    public function test_the_confirmation_gives_the_booked_branchs_address(): void
    {
        $this->tura->update(['address' => '5 شارع الترعة']);
        $this->seed(MessageTemplateSeeder::class);
        $booking = $this->book($this->tura, '17:00');

        $payload = app(TemplatePayloadResolver::class)
            ->for(MessageTemplate::query()->where('key', 'booking_confirmed')->firstOrFail(), $booking, $this->clinic);

        $this->assertContains('5 شارع الترعة', $payload->body);
    }

    public function test_a_deactivated_branchs_evening_does_not_hold_back_the_clinic_day(): void
    {
        app(ScheduleService::class)->updateDay($this->tura, ScheduleDayData::fromArray(DayOfWeek::FRIDAY, [
            'is_open' => true,
            'periods' => [['start_time' => '21:00', 'end_time' => '02:00']],
        ]));
        app(BranchService::class)->deactivate($this->tura);

        Carbon::setTestNow(Carbon::parse('2026-12-05 01:00:00', 'Africa/Cairo'));

        $this->assertSame(self::SATURDAY, $this->clinic->fresh()->currentDay()->toDateString());
    }
}
