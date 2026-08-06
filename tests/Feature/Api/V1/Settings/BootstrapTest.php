<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Enums\DayOfWeek;
use App\Models\ClinicHoliday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
    }

    public function test_it_returns_everything_the_app_needs_on_launch(): void
    {
        ClinicHoliday::factory()->create(['clinic_id' => $this->clinic->id]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson(route('api.v1.bootstrap'))
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'clinic' => [
                        'id', 'name', 'specialty', 'timezone',
                        'booking_window_days', 'first_visit_only_days', 'slot_step_minutes',
                    ],
                    'visit_types',
                    'schedule',
                    'holidays',
                    'abilities',
                    'user' => ['id', 'name', 'role'],
                ],
            ]);

        $this->assertSame($this->clinic->id, $response->json('data.clinic.id'));
        $this->assertCount(3, $response->json('data.visit_types'));
        $this->assertCount(7, $response->json('data.schedule'));
        $this->assertCount(1, $response->json('data.holidays'));
    }

    public function test_it_reflects_the_clinics_own_settings_not_the_system_defaults(): void
    {
        $this->clinic->update(['booking_window_days' => 14, 'first_visit_only_days' => 90]);

        Sanctum::actingAs($this->owner);

        $this->getJson(route('api.v1.bootstrap'))
            ->assertOk()
            ->assertJsonPath('data.clinic.booking_window_days', 14)
            ->assertJsonPath('data.clinic.first_visit_only_days', 90);
    }

    public function test_the_schedule_comes_back_saturday_first_with_its_periods(): void
    {
        $saturday = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $saturday->update(['is_open' => true]);
        $saturday->periods()->create(['start_time' => '13:00', 'end_time' => '15:00']);
        $saturday->periods()->create(['start_time' => '17:00', 'end_time' => '21:00']);

        Sanctum::actingAs($this->owner);

        $schedule = $this->getJson(route('api.v1.bootstrap'))->json('data.schedule');

        $this->assertSame(0, $schedule[0]['day_of_week']['value']);
        $this->assertTrue($schedule[0]['is_open']);
        $this->assertCount(2, $schedule[0]['periods']);
        $this->assertFalse($schedule[1]['is_open']);
    }

    public function test_a_secretarys_payload_carries_no_prices(): void
    {
        Sanctum::actingAs($this->secretary);

        $visitTypes = $this->getJson(route('api.v1.bootstrap'))->json('data.visit_types');

        foreach ($visitTypes as $visitType) {
            $this->assertArrayNotHasKey('price', $visitType);
        }
    }

    public function test_abilities_tell_the_app_which_screens_to_show(): void
    {
        Sanctum::actingAs($this->secretary);
        $secretary = $this->getJson(route('api.v1.bootstrap'))->json('data.abilities');

        Sanctum::actingAs($this->owner);
        $owner = $this->getJson(route('api.v1.bootstrap'))->json('data.abilities');

        $this->assertContains('bookings.manage', $secretary);
        $this->assertNotContains('reports.view', $secretary);
        $this->assertContains('reports.view', $owner);
    }

    public function test_it_requires_a_clinic(): void
    {
        Sanctum::actingAs(User::factory()->secretary()->create());

        $this->getJson(route('api.v1.bootstrap'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CLINIC_NOT_ASSIGNED');
    }

    /**
     * With no usable Accept-Language, the account's stored preference decides.
     */
    public function test_the_stored_locale_applies_when_no_language_header_is_sent(): void
    {
        $this->owner->update(['locale' => 'en']);

        Sanctum::actingAs($this->owner);

        $this->withHeader('Accept-Language', 'fr')
            ->getJson(route('api.v1.bootstrap'))
            ->assertOk()
            ->assertJsonPath('message', 'Clinic settings loaded');
    }

    public function test_an_explicit_language_header_beats_the_stored_locale(): void
    {
        $this->owner->update(['locale' => 'en']);

        Sanctum::actingAs($this->owner);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson(route('api.v1.bootstrap'))
            ->assertOk()
            ->assertJsonPath('message', 'تم تحميل إعدادات العيادة');
    }
}
