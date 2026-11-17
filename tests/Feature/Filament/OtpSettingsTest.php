<?php

namespace Tests\Feature\Filament;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\SystemSettingsPage;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Settings\SystemSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The super admin switches OTP for the whole project on the system settings
 * page, and for one clinic on its own form.
 */
class OtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);

        config(['clinic.self_booking.require_otp' => true]);
    }

    public function test_the_page_shows_the_current_project_switch(): void
    {
        Livewire::test(SystemSettingsPage::class)
            ->assertOk()
            ->assertSchemaStateSet(['self_booking.require_otp' => true]);
    }

    public function test_the_admin_switches_otp_off_for_the_whole_project(): void
    {
        Livewire::test(SystemSettingsPage::class)
            ->fillForm(['self_booking.require_otp' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(app(SystemSettings::class)->requireOtp());
        $this->assertDatabaseHas('system_settings', [
            'key' => SystemSettings::REQUIRE_OTP,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_only_a_super_admin_reaches_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::CLINIC]));

        $this->assertFalse(SystemSettingsPage::canAccess());
    }

    public function test_a_clinic_is_switched_off_on_its_own_form(): void
    {
        $clinic = Clinic::factory()->create(['phone' => '+201001234567', 'slug' => 'dr-test']);
        app(ProvisionClinicAction::class)->execute($clinic, 'secret-password');

        Livewire::test(EditClinic::class, ['record' => $clinic->getRouteKey()])
            ->fillForm(['otp_required' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($clinic->fresh()->otp_required);
    }
}
