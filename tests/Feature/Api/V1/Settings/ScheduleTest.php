<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Enums\DayOfWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        Sanctum::actingAs($this->owner);
    }

    public function test_it_returns_all_seven_days_saturday_first(): void
    {
        $days = $this->getJson(route('api.v1.schedule.index'))
            ->assertOk()
            ->json('data.days');

        $this->assertCount(7, $days);
        $this->assertSame(0, $days[0]['day_of_week']['value']);
        $this->assertSame('السبت', $days[0]['day_of_week']['display']);
        $this->assertSame(6, $days[6]['day_of_week']['value']);
        $this->assertSame('الجمعة', $days[6]['day_of_week']['display']);
    }

    public function test_a_provisioned_clinic_starts_with_every_day_closed(): void
    {
        $days = $this->getJson(route('api.v1.schedule.index'))->json('data.days');

        foreach ($days as $day) {
            $this->assertFalse($day['is_open']);
            $this->assertSame([], $day['periods']);
        }
    }

    public function test_a_day_can_have_several_separate_periods(): void
    {
        $this->putJson(route('api.v1.schedule.update', DayOfWeek::SATURDAY->value), [
            'is_open' => true,
            'periods' => [
                ['start_time' => '13:00', 'end_time' => '15:00'],
                ['start_time' => '17:00', 'end_time' => '21:00'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonCount(2, 'data.periods')
            ->assertJsonPath('data.periods.0.start_time.value', '13:00')
            ->assertJsonPath('data.periods.0.start_time.display', '1:00 م')
            ->assertJsonPath('data.periods.1.end_time.value', '21:00');
    }

    public function test_saving_a_day_replaces_its_periods_rather_than_appending(): void
    {
        $day = DayOfWeek::SUNDAY->value;

        $this->putJson(route('api.v1.schedule.update', $day), [
            'is_open' => true,
            'periods' => [['start_time' => '09:00', 'end_time' => '14:00']],
        ])->assertOk();

        $this->putJson(route('api.v1.schedule.update', $day), [
            'is_open' => true,
            'periods' => [['start_time' => '10:00', 'end_time' => '12:00']],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.periods')
            ->assertJsonPath('data.periods.0.start_time.value', '10:00');

        $this->assertSame(
            1,
            $this->clinic->scheduleFor(DayOfWeek::SUNDAY)->periods()->count(),
        );
    }

    public function test_closing_a_day_drops_its_periods(): void
    {
        $day = DayOfWeek::MONDAY->value;

        $this->putJson(route('api.v1.schedule.update', $day), [
            'is_open' => true,
            'periods' => [['start_time' => '09:00', 'end_time' => '14:00']],
        ])->assertOk();

        $this->putJson(route('api.v1.schedule.update', $day), [
            'is_open' => false,
            'periods' => [['start_time' => '09:00', 'end_time' => '14:00']],
        ])
            ->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.periods', []);
    }

    public function test_an_open_day_needs_at_least_one_period(): void
    {
        $this->putJson(route('api.v1.schedule.update', DayOfWeek::TUESDAY->value), [
            'is_open' => true,
            'periods' => [],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_INVALID');
    }

    public function test_a_period_that_ends_before_it_starts_is_rejected(): void
    {
        $this->putJson(route('api.v1.schedule.update', DayOfWeek::TUESDAY->value), [
            'is_open' => true,
            'periods' => [['start_time' => '15:00', 'end_time' => '13:00']],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_INVALID');
    }

    public function test_overlapping_periods_are_rejected(): void
    {
        $this->putJson(route('api.v1.schedule.update', DayOfWeek::WEDNESDAY->value), [
            'is_open' => true,
            'periods' => [
                ['start_time' => '09:00', 'end_time' => '14:00'],
                ['start_time' => '13:00', 'end_time' => '18:00'],
            ],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_OVERLAP');

        $this->assertSame(0, $this->clinic->scheduleFor(DayOfWeek::WEDNESDAY)->periods()->count());
    }

    public function test_back_to_back_periods_are_allowed(): void
    {
        $this->putJson(route('api.v1.schedule.update', DayOfWeek::THURSDAY->value), [
            'is_open' => true,
            'periods' => [
                ['start_time' => '09:00', 'end_time' => '12:00'],
                ['start_time' => '12:00', 'end_time' => '15:00'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data.periods');
    }

    public function test_an_unknown_day_number_is_rejected(): void
    {
        $this->putJson(route('api.v1.schedule.update', 9), [
            'is_open' => false,
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'SCHEDULE_DAY_NOT_FOUND');
    }

    public function test_it_validates_the_time_format(): void
    {
        $this->putJson(route('api.v1.schedule.update', DayOfWeek::SATURDAY->value), [
            'is_open' => true,
            'periods' => [['start_time' => 'morning', 'end_time' => '14:00']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_secretary_may_also_edit_the_schedule(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->putJson(route('api.v1.schedule.update', DayOfWeek::SATURDAY->value), [
            'is_open' => true,
            'periods' => [['start_time' => '09:00', 'end_time' => '14:00']],
        ])->assertOk();
    }

    public function test_one_clinics_hours_do_not_touch_another(): void
    {
        $other = $this->otherClinic();

        $this->putJson(route('api.v1.schedule.update', DayOfWeek::SATURDAY->value), [
            'is_open' => true,
            'periods' => [['start_time' => '09:00', 'end_time' => '14:00']],
        ])->assertOk();

        $this->assertFalse($other->scheduleFor(DayOfWeek::SATURDAY)->is_open);
    }
}
