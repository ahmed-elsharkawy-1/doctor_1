<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ClinicHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        Sanctum::actingAs($this->owner);
    }

    public function test_it_adds_a_holiday(): void
    {
        $date = Carbon::today()->addDays(5)->toDateString();

        $this->postJson(route('api.v1.holidays.store'), [
            'date' => $date,
            'note' => 'سفر',
        ])
            ->assertCreated()
            ->assertJsonPath('data.date.value', $date)
            ->assertJsonPath('data.note', 'سفر');

        $this->assertDatabaseHas('clinic_holidays', [
            'clinic_id' => $this->clinic->id,
            'note' => 'سفر',
        ]);
    }

    public function test_the_same_date_cannot_be_added_twice(): void
    {
        $date = Carbon::today()->addDays(5)->toDateString();

        $this->postJson(route('api.v1.holidays.store'), ['date' => $date])->assertCreated();

        $this->postJson(route('api.v1.holidays.store'), ['date' => $date])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HOLIDAY_ALREADY_EXISTS');
    }

    /**
     * Closing a day that already has patients booked is almost always a
     * mistake — the postpone flow is the right tool.
     */
    public function test_closing_a_day_with_bookings_is_refused_and_reports_the_count(): void
    {
        $date = Carbon::today()->addDays(2);

        Booking::factory()->count(3)->forClinic($this->clinic)->at($date->copy()->setTime(9, 0))->create();

        $this->postJson(route('api.v1.holidays.store'), ['date' => $date->toDateString()])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HOLIDAY_HAS_BOOKINGS')
            ->assertJsonPath('error.details.bookings_count', 3);

        $this->assertDatabaseCount('clinic_holidays', 0);
    }

    public function test_it_can_be_forced_once_the_secretary_confirms(): void
    {
        $date = Carbon::today()->addDays(2);

        Booking::factory()->forClinic($this->clinic)->at($date->copy()->setTime(9, 0))->create();

        $this->postJson(route('api.v1.holidays.store'), [
            'date' => $date->toDateString(),
            'force' => true,
        ])->assertCreated();

        $this->assertDatabaseCount('clinic_holidays', 1);
    }

    public function test_cancelled_bookings_do_not_block_a_holiday(): void
    {
        $date = Carbon::today()->addDays(2);

        Booking::factory()
            ->forClinic($this->clinic)
            ->at($date->copy()->setTime(9, 0))
            ->cancelled()
            ->create();

        $this->postJson(route('api.v1.holidays.store'), ['date' => $date->toDateString()])
            ->assertCreated();
    }

    public function test_completed_bookings_do_not_block_a_holiday(): void
    {
        $date = Carbon::today()->addDays(2);

        Booking::factory()
            ->forClinic($this->clinic)
            ->at($date->copy()->setTime(9, 0))
            ->done()
            ->create();

        $this->assertSame(
            BookingStatus::DONE,
            $this->clinic->bookings()->first()->status,
        );

        $this->postJson(route('api.v1.holidays.store'), ['date' => $date->toDateString()])
            ->assertCreated();
    }

    public function test_it_lists_upcoming_holidays_only_by_default(): void
    {
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => Carbon::today()->subDays(10)->toDateString(),
        ]);
        ClinicHoliday::factory()->create([
            'clinic_id' => $this->clinic->id,
            'date' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $this->assertCount(1, $this->getJson(route('api.v1.holidays.index'))->json('data.items'));

        $this->assertCount(
            2,
            $this->getJson(route('api.v1.holidays.index', ['include_past' => 1]))->json('data.items'),
        );
    }

    public function test_it_removes_a_holiday(): void
    {
        $holiday = ClinicHoliday::factory()->create(['clinic_id' => $this->clinic->id]);

        $this->deleteJson(route('api.v1.holidays.destroy', $holiday))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('clinic_holidays', ['id' => $holiday->id]);
    }

    public function test_another_clinics_holiday_is_not_reachable(): void
    {
        $foreign = ClinicHoliday::factory()->create(['clinic_id' => $this->otherClinic()->id]);

        $this->deleteJson(route('api.v1.holidays.destroy', $foreign))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOLIDAY_NOT_FOUND');

        $this->assertDatabaseHas('clinic_holidays', ['id' => $foreign->id]);
    }

    public function test_it_validates_the_date_format(): void
    {
        $this->postJson(route('api.v1.holidays.store'), ['date' => '5 أغسطس'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['date']]]);
    }
}
