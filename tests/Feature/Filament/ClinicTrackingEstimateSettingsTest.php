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
 * The expected time on the tracking page is turned on clinic by clinic, by a
 * super admin, under the platform switch: both must be on for it to show.
 */
class ClinicTrackingEstimateSettingsTest extends TestCase
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

        config(['clinic.tracking.show_estimate' => true]);
    }

    public function test_every_clinic_starts_off(): void
    {
        $this->assertFalse($this->clinic->fresh()->showsTrackingEstimate());
        $this->assertFalse((new Clinic)->showsTrackingEstimate());
    }

    public function test_a_super_admin_can_switch_it_on(): void
    {
        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm(['tracking_estimate_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->clinic->fresh()->showsTrackingEstimate());
    }

    public function test_the_clinic_switch_means_nothing_while_the_platform_switch_is_off(): void
    {
        $this->clinic->update(['tracking_estimate_enabled' => true]);
        config(['clinic.tracking.show_estimate' => false]);

        $this->assertFalse($this->clinic->fresh()->showsTrackingEstimate());
    }
}
