<?php

namespace Tests\Feature\Api\V1\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        Sanctum::actingAs($this->owner);
    }

    public function test_it_updates_the_booking_window(): void
    {
        $this->putJson(route('api.v1.settings.general'), ['booking_window_days' => 14])
            ->assertOk()
            ->assertJsonPath('data.booking_window_days', 14);

        $this->assertSame(14, $this->clinic->refresh()->booking_window_days);
    }

    public function test_it_updates_the_retention_window(): void
    {
        $this->putJson(route('api.v1.settings.general'), ['first_visit_only_days' => 90])
            ->assertOk()
            ->assertJsonPath('data.first_visit_only_days', 90);

        $this->assertSame(90, $this->clinic->refresh()->first_visit_only_days);
    }

    /**
     * The app can save one field without echoing the other back.
     */
    public function test_omitting_a_field_leaves_it_untouched(): void
    {
        $original = $this->clinic->first_visit_only_days;

        $this->putJson(route('api.v1.settings.general'), ['booking_window_days' => 10])->assertOk();

        $this->clinic->refresh();

        $this->assertSame(10, $this->clinic->booking_window_days);
        $this->assertSame($original, $this->clinic->first_visit_only_days);
    }

    public function test_an_empty_payload_changes_nothing(): void
    {
        $before = $this->clinic->only(['booking_window_days', 'first_visit_only_days']);

        $this->putJson(route('api.v1.settings.general'), [])->assertOk();

        $this->assertSame($before, $this->clinic->refresh()->only(['booking_window_days', 'first_visit_only_days']));
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $this->putJson(route('api.v1.settings.general'), [
            'booking_window_days' => 0,
            'first_visit_only_days' => 5000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => ['fields' => ['booking_window_days', 'first_visit_only_days']],
            ]);
    }

    public function test_it_never_touches_another_clinic(): void
    {
        $other = $this->otherClinic();
        $before = $other->booking_window_days;

        $this->putJson(route('api.v1.settings.general'), ['booking_window_days' => 21])->assertOk();

        $this->assertSame($before, $other->refresh()->booking_window_days);
    }
}
