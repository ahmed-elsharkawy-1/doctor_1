<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\DayOfWeek;
use App\Models\SlotHold;
use App\Services\V1\Booking\SlotHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * `POST /slot-holds` and `DELETE /slot-holds/{token}` — what the mobile app
 * calls the moment a time is tapped, and again on the way out.
 */
class SlotHoldEndpointTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_holds_a_slot_and_hands_back_a_token(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson(route('api.v1.slot-holds.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.start_time.value', '09:00')
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'expires_in_seconds']]);

        $this->assertDatabaseHas('slot_holds', [
            'clinic_id' => $this->clinic->id,
            'token' => $response->json('data.token'),
        ]);
    }

    /**
     * The point of the token: the grid must keep offering the slot to the
     * screen that is holding it, while hiding it from everyone else.
     */
    public function test_the_slots_grid_hides_a_hold_from_everyone_but_its_holder(): void
    {
        Sanctum::actingAs($this->owner);

        $token = $this->postJson(route('api.v1.slot-holds.store'), $this->payload())
            ->assertCreated()
            ->json('data.token');

        $query = ['date' => self::DAY, 'visit_type_id' => $this->visitTypeId()];

        $this->assertFalse($this->slotState($query, '09:00'));
        $this->assertTrue($this->slotState($query + ['hold_token' => $token], '09:00'));
    }

    public function test_a_second_caller_is_refused_a_held_slot(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.slot-holds.store'), $this->payload())->assertCreated();

        Sanctum::actingAs($this->secretary);

        $this->postJson(route('api.v1.slot-holds.store'), $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    public function test_sending_the_token_back_moves_the_claim(): void
    {
        Sanctum::actingAs($this->owner);

        $token = $this->postJson(route('api.v1.slot-holds.store'), $this->payload())
            ->json('data.token');

        $this->postJson(route('api.v1.slot-holds.store'), $this->payload([
            'start_time' => '09:20',
            'token' => $token,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.token', $token)
            ->assertJsonPath('data.start_time.value', '09:20');

        $this->assertSame(1, SlotHold::count());
    }

    public function test_a_held_slot_can_be_booked_with_its_token(): void
    {
        Sanctum::actingAs($this->owner);

        $token = $this->postJson(route('api.v1.slot-holds.store'), $this->payload())
            ->json('data.token');

        $this->postJson(route('api.v1.bookings.store'), [
            'patient_name' => 'سارة أحمد',
            'phone' => '01012225521',
            'visit_type_id' => $this->visitTypeId(),
            'date' => self::DAY,
            'start_time' => '09:00',
            'hold_token' => $token,
        ])->assertCreated();

        $this->assertSame(0, SlotHold::count());
    }

    public function test_releasing_gives_the_slot_back(): void
    {
        Sanctum::actingAs($this->owner);

        $token = $this->postJson(route('api.v1.slot-holds.store'), $this->payload())
            ->json('data.token');

        $this->deleteJson(route('api.v1.slot-holds.destroy', $token))->assertOk();

        $this->assertSame(0, SlotHold::count());
    }

    /**
     * A client tidying up on its way out should not have to care whether the
     * hold had already lapsed or been booked.
     */
    public function test_releasing_something_that_is_not_there_still_succeeds(): void
    {
        Sanctum::actingAs($this->owner);

        $this->deleteJson(route('api.v1.slot-holds.destroy', 'never-existed'))->assertOk();
    }

    /**
     * Scoped from the token like everything else — a hold belonging to another
     * clinic is not this caller's to free.
     */
    public function test_another_clinics_hold_cannot_be_released(): void
    {
        $other = $this->otherClinic();

        // Provisioning leaves every day closed, so the other clinic needs
        // hours of its own before it can hold anything.
        $theirSchedule = $other->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $theirSchedule->update(['is_open' => true]);
        $theirSchedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $theirs = app(SlotHoldService::class)->hold(
            $other,
            (int) $other->visitTypes()->active()->value('id'),
            self::DAY,
            '09:00',
        );

        Sanctum::actingAs($this->owner);

        $this->deleteJson(route('api.v1.slot-holds.destroy', $theirs->token))->assertOk();

        $this->assertDatabaseHas('slot_holds', ['token' => $theirs->token]);
    }

    public function test_it_needs_a_token_of_its_own(): void
    {
        $this->postJson(route('api.v1.slot-holds.store'), $this->payload())
            ->assertUnauthorized();
    }

    public function test_it_validates_the_payload(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.slot-holds.store'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['date', 'start_time', 'visit_type_id']]]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => self::DAY,
            'start_time' => '09:00',
            'visit_type_id' => $this->visitTypeId(),
        ], $overrides);
    }

    private function visitTypeId(): int
    {
        return (int) $this->clinic->visitTypes()->active()->value('id');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function slotState(array $query, string $time): bool
    {
        $slots = $this->getJson(route('api.v1.slots', $query))->assertOk()->json('data.slots');

        foreach ($slots as $slot) {
            if ($slot['start_time']['value'] === $time) {
                return $slot['is_available'];
            }
        }

        $this->fail("The clinic never offers {$time}.");
    }
}
