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
 * The super admin's switch for the no-show rule.
 */
class ClinicNoShowBlockSettingTest extends TestCase
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

    public function test_it_is_off_until_somebody_switches_it_on(): void
    {
        $this->assertFalse($this->clinic->fresh()->no_show_block_enabled);
    }

    public function test_a_super_admin_switches_it_on(): void
    {
        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm(['no_show_block_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->clinic->fresh()->no_show_block_enabled);
    }
}
