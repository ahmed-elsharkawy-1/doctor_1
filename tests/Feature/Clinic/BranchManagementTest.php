<?php

namespace Tests\Feature\Clinic;

use App\Livewire\App\Settings\Branches;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-16: the doctor adds, edits and deactivates branches.
 */
class BranchManagementTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const GEDEILA = [
        'name' => 'عيادة جديلة',
        'address' => '15 شارع الجمهورية، جديلة',
        'latitude' => 31.0412,
        'longitude' => 31.3712,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['address' => '١٢ شارع مصدق، الدقي', 'latitude' => 30.0384, 'longitude' => 31.2101]);
    }

    private function first(): Branch
    {
        return $this->clinic->branches()->firstOrFail();
    }

    private function allowAdding(): void
    {
        config(['clinic.branches.multi_enabled' => true]);
    }

    public function test_everyone_sees_the_branches_with_the_clinics_details_filled_in(): void
    {
        Sanctum::actingAs(User::factory()->secretary([])->inClinic($this->clinic)->create());

        $this->getJson(route('api.v1.branches.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data.branches')
            ->assertJsonPath('data.branches.0.address', '١٢ شارع مصدق، الدقي')
            ->assertJsonPath('data.branches.0.own_phone', false)
            ->assertJsonPath('data.can_add', false);
    }

    public function test_a_second_branch_cannot_be_added_until_multi_branch_is_switched_on(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.branches.store'), self::GEDEILA)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'BRANCH_ADDING_DISABLED');

        $this->assertSame(1, $this->clinic->branches()->count());
    }

    public function test_the_doctor_adds_a_branch_from_a_pasted_map_link(): void
    {
        $this->allowAdding();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.branches.store'), [
            'name' => 'عيادة جديلة',
            'address' => '15 شارع الجمهورية، جديلة',
            'map_link' => 'https://www.google.com/maps/@31.0412,31.3712,17z',
            'phone' => '01099999999',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'عيادة جديلة')
            ->assertJsonPath('data.latitude', 31.0412)
            ->assertJsonPath('data.own_phone', true);

        $branch = $this->clinic->branches()->where('name', 'عيادة جديلة')->firstOrFail();
        $this->assertSame('+201099999999', $branch->phone);
        $this->assertTrue($branch->is_active);
    }

    public function test_names_are_unique_per_doctor(): void
    {
        $this->allowAdding();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.branches.store'), ['name' => $this->first()->name] + self::GEDEILA)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BRANCH_DUPLICATE_NAME');
    }

    public function test_an_unreadable_map_link_or_a_missing_pin_is_refused(): void
    {
        $this->allowAdding();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.branches.store'), [
            'name' => 'عيادة جديلة',
            'address' => 'جديلة',
            'map_link' => 'https://maps.app.goo.gl/AbCdEf',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['map_link']]]);

        $this->postJson(route('api.v1.branches.store'), ['name' => 'عيادة جديلة', 'address' => 'جديلة'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['latitude', 'longitude']]]);
    }

    public function test_editing_the_first_branch_edits_the_clinics_own_address(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.branches.update', $this->first()), [
            'name' => 'عيادة الدقي',
            'address' => '20 شارع التحرير، الدقي',
            'latitude' => 30.04,
            'longitude' => 31.21,
        ])->assertOk()->assertJsonPath('data.address', '20 شارع التحرير، الدقي');

        // One place for the address: the public page and the messages, which
        // still read the clinic, show the edit straight away.
        $this->assertSame('20 شارع التحرير، الدقي', $this->clinic->fresh()->address);
        $this->assertNull($this->first()->address);
        $this->assertSame('عيادة الدقي', $this->first()->name);
    }

    public function test_editing_another_branch_leaves_the_clinic_alone(): void
    {
        $other = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.branches.update', $other), self::GEDEILA)->assertOk();

        $this->assertSame('15 شارع الجمهورية، جديلة', $other->fresh()->address);
        $this->assertSame('١٢ شارع مصدق، الدقي', $this->clinic->fresh()->address);
    }

    public function test_the_last_active_branch_cannot_be_deactivated(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.branches.deactivate', $this->first()))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BRANCH_LAST_ACTIVE');

        $this->assertTrue($this->first()->is_active);
    }

    public function test_a_branch_is_deactivated_and_reactivated_with_its_data_kept(): void
    {
        $other = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.branches.deactivate', $other))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Now the first is the last active one.
        $this->postJson(route('api.v1.branches.deactivate', $this->first()))->assertStatus(409);

        $this->postJson(route('api.v1.branches.activate', $other))
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_another_clinics_branch_is_not_found(): void
    {
        $foreign = $this->otherClinic()->branches()->firstOrFail();
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.branches.update', $foreign), self::GEDEILA)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'BRANCH_NOT_FOUND');
    }

    public function test_changing_branches_needs_manage_branches(): void
    {
        $assistant = User::factory()->secretary([])->inClinic($this->clinic)->create();
        Sanctum::actingAs($assistant);

        $this->putJson(route('api.v1.branches.update', $this->first()), self::GEDEILA)->assertForbidden();

        $assistant->update(['permissions' => ['branches.manage']]);

        $this->putJson(route('api.v1.branches.update', $this->first()), self::GEDEILA)->assertOk();
    }

    public function test_the_settings_screen_edits_a_branch(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Branches::class)
            ->assertDontSee(__('app.settings.add_branch'))
            ->call('startEditing', $this->first()->id)
            ->assertSet('address', '١٢ شارع مصدق، الدقي')
            ->set('name', 'عيادة الدقي')
            ->set('map_link', 'https://maps.google.com/?q=30.05,31.22')
            ->call('save')
            ->assertSet('failed', false)
            ->assertSet('editing', null);

        $this->assertSame('عيادة الدقي', $this->first()->name);
        $this->assertEqualsWithDelta(30.05, (float) $this->clinic->fresh()->latitude, 0.00001);
    }

    public function test_the_settings_screen_adds_and_confirms_before_deactivating(): void
    {
        $this->allowAdding();

        $screen = Livewire::actingAs($this->owner)
            ->test(Branches::class)
            ->call('startAdding')
            ->set('name', 'عيادة جديلة')
            ->set('address', 'جديلة')
            ->set('latitude', '31.04')
            ->set('longitude', '31.37')
            ->call('save')
            ->assertSet('failed', false);

        $gedeila = $this->clinic->branches()->where('name', 'عيادة جديلة')->firstOrFail();

        $screen->call('askToDeactivate', $gedeila->id)
            ->assertSee(__('app.settings.branch_deactivate_confirm'));
        $this->assertTrue($gedeila->fresh()->is_active);

        $screen->call('deactivate')->assertSet('failed', false);
        $this->assertFalse($gedeila->fresh()->is_active);
    }

    public function test_the_settings_screen_needs_manage_branches(): void
    {
        Livewire::actingAs($this->secretary)->test(Branches::class)->assertForbidden();
    }
}
