<?php

namespace Tests\Feature\Filament;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Models\Clinic;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two per-clinic settings behind the tracking page's delay notice.
 */
class ClinicTrackingDelaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->clinic = Clinic::factory()->create(['phone' => '+201001234567', 'slug' => 'dr-test']);
        app(ProvisionClinicAction::class)->execute($this->clinic, 'secret-password');
    }

    public function test_a_clinic_can_be_given_its_own_values(): void
    {
        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm([
                'tracking_delay_reduction_minutes' => 20,
                'tracking_delay_threshold_minutes' => 10,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(20, $this->clinic->fresh()->trackingDelayReductionMinutes());
        $this->assertSame(10, $this->clinic->fresh()->trackingDelayThresholdMinutes());
    }

    public function test_left_empty_they_follow_the_platform_defaults(): void
    {
        $this->assertNull($this->clinic->tracking_delay_reduction_minutes);
        $this->assertSame(30, $this->clinic->trackingDelayReductionMinutes());
        $this->assertSame(15, $this->clinic->trackingDelayThresholdMinutes());
    }

    /**
     * The expected time can fall behind "now" by up to the reduction, so
     * neither may be more than an hour — a typo must not make it hours.
     */
    public function test_neither_may_be_more_than_an_hour(): void
    {
        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm([
                'tracking_delay_reduction_minutes' => 61,
                'tracking_delay_threshold_minutes' => 90,
            ])
            ->call('save')
            ->assertHasFormErrors([
                'tracking_delay_reduction_minutes' => 'max',
                'tracking_delay_threshold_minutes' => 'max',
            ]);

        $this->assertNull($this->clinic->fresh()->tracking_delay_reduction_minutes);
    }
}
