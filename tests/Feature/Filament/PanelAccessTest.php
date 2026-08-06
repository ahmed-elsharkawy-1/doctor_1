<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Clinics\ClinicResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Clinic;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_a_secretary_cannot_access_the_panel(): void
    {
        $clinic = Clinic::factory()->create();
        $secretary = User::factory()->secretary()->inClinic($clinic)->create();

        $this->assertFalse($secretary->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_super_admins_and_owners_can_access_the_panel(): void
    {
        $clinic = Clinic::factory()->create();

        $this->assertTrue(
            User::factory()->superAdmin()->create()
                ->canAccessPanel(Filament::getPanel('admin')),
        );

        $this->assertTrue(
            User::factory()->owner()->inClinic($clinic)->create()
                ->canAccessPanel(Filament::getPanel('admin')),
        );
    }

    public function test_a_deactivated_account_cannot_access_the_panel(): void
    {
        $this->assertFalse(
            User::factory()->superAdmin()->inactive()->create()
                ->canAccessPanel(Filament::getPanel('admin')),
        );
    }

    public function test_only_super_admins_manage_clinics(): void
    {
        $clinic = Clinic::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->assertTrue(ClinicResource::canViewAny());

        $this->actingAs(User::factory()->owner()->inClinic($clinic)->create());
        $this->assertFalse(ClinicResource::canViewAny());
    }

    public function test_an_owner_only_sees_staff_from_their_own_clinic(): void
    {
        $mine = Clinic::factory()->create();
        $theirs = Clinic::factory()->create();

        $owner = User::factory()->owner()->inClinic($mine)->create();
        $mySecretary = User::factory()->secretary()->inClinic($mine)->create();
        $theirSecretary = User::factory()->secretary()->inClinic($theirs)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($owner);

        $visible = UserResource::getEloquentQuery()->pluck('id');

        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue($visible->contains($mySecretary->id));
        $this->assertTrue($visible->contains($owner->id));
        $this->assertFalse($visible->contains($theirSecretary->id));
        $this->assertFalse($visible->contains($superAdmin->id));
    }

    public function test_a_super_admin_sees_every_account(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->secretary()->inClinic($clinic)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        $this->assertSame(2, UserResource::getEloquentQuery()->count());
    }
}
