<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Filament\Admin\Resources\Doctors\DoctorResource;
use App\Filament\Admin\Resources\Specialties\SpecialtyResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The panel belongs to the platform operator alone.
 *
 * A clinic is run entirely from the mobile app — bookings, queue, settings and
 * reports. No doctor and no secretary ever signs in here.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private function panel(): Panel
    {
        return Filament::getPanel('admin');
    }

    public function test_only_the_super_admin_can_open_the_panel(): void
    {
        $clinic = Clinic::factory()->create();

        $superAdmin = User::factory()->superAdmin()->create();
        $owner = User::factory()->owner()->inClinic($clinic)->create();
        $secretary = User::factory()->secretary()->inClinic($clinic)->create();

        $this->assertTrue($superAdmin->canAccessPanel($this->panel()));
        $this->assertFalse($owner->canAccessPanel($this->panel()));
        $this->assertFalse($secretary->canAccessPanel($this->panel()));
    }

    public function test_a_deactivated_super_admin_cannot_open_the_panel(): void
    {
        $this->assertFalse(
            User::factory()->superAdmin()->inactive()->create()->canAccessPanel($this->panel()),
        );
    }

    public function test_database_seeder_falls_back_when_the_super_admin_password_env_is_blank(): void
    {
        config()->set('clinic.super_admin.password', '');

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@doctor1.test')->firstOrFail();

        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue($admin->canAccessPanel($this->panel()));
    }

    /**
     * Discovery is by namespace, so a resource in the wrong one is silently
     * invisible rather than broken — assert registration, not just the class.
     */
    public function test_every_resource_is_registered_with_the_panel(): void
    {
        $registered = $this->panel()->getResources();

        foreach ([ClinicResource::class, DoctorResource::class, SpecialtyResource::class, UserResource::class] as $resource) {
            $this->assertContains($resource, $registered, "{$resource} is not discovered by the panel");
        }
    }

    public function test_the_panel_manages_the_platform_not_a_practice(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertTrue(ClinicResource::canViewAny());
        $this->assertTrue(DoctorResource::canViewAny());
        $this->assertTrue(SpecialtyResource::canViewAny());
        $this->assertTrue(UserResource::canViewAny());
    }

    public function test_an_owner_can_reach_nothing_in_the_panel(): void
    {
        $clinic = Clinic::factory()->create();

        $this->actingAs(User::factory()->owner()->inClinic($clinic)->create());

        $this->assertFalse(ClinicResource::canViewAny());
        $this->assertFalse(DoctorResource::canViewAny());
        $this->assertFalse(SpecialtyResource::canViewAny());
        // Staff management moved to the platform operator with the panel.
        $this->assertFalse(UserResource::canViewAny());
    }

    public function test_nothing_in_the_panel_is_deletable(): void
    {
        $clinic = Clinic::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertFalse(ClinicResource::canDelete($clinic));
    }
}
