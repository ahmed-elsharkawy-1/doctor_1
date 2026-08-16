<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Models\VisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class VisitTypeTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
    }

    public function test_it_lists_the_clinics_active_visit_types(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson(route('api.v1.visit-types.index'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(
            ['كشف', 'إعادة', 'إجراء'],
            array_column($response->json('data.items'), 'name'),
        );
    }

    public function test_hidden_types_are_excluded_unless_asked_for(): void
    {
        VisitType::factory()->hidden()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'نوع قديم',
        ]);

        Sanctum::actingAs($this->owner);

        $this->assertCount(3, $this->getJson(route('api.v1.visit-types.index'))->json('data.items'));

        $this->assertCount(
            4,
            $this->getJson(route('api.v1.visit-types.index', ['include_hidden' => 1]))->json('data.items'),
        );
    }

    public function test_the_clinic_account_sees_prices(): void
    {
        $this->clinic->visitTypes()->first()->update(['price' => 300]);

        Sanctum::actingAs($this->secretary);
        $item = $this->getJson(route('api.v1.visit-types.index'))->json('data.items.0');

        $this->assertSame('300.00', $item['price']['value']);
        $this->assertFalse($item['needs_price']);
    }

    public function test_a_provisioned_type_is_flagged_as_needing_a_price(): void
    {
        Sanctum::actingAs($this->owner);

        $this->assertTrue(
            $this->getJson(route('api.v1.visit-types.index'))->json('data.items.0.needs_price'),
        );
    }

    public function test_an_owner_can_create_a_visit_type_with_a_price(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.visit-types.store'), [
            'name' => 'سونار',
            'duration_minutes' => 15,
            'price' => 250,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'سونار')
            ->assertJsonPath('data.duration_minutes', 15)
            ->assertJsonPath('data.price.value', '250.00');

        $this->assertDatabaseHas('visit_types', [
            'clinic_id' => $this->clinic->id,
            'name' => 'سونار',
            'price' => 250,
        ]);
    }

    public function test_the_clinic_account_can_create_a_type_with_a_price(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->postJson(route('api.v1.visit-types.store'), [
            'name' => 'سونار',
            'duration_minutes' => 15,
            'price' => 250,
        ])->assertCreated();

        $this->assertDatabaseHas('visit_types', [
            'clinic_id' => $this->clinic->id,
            'name' => 'سونار',
            'price' => 250,
        ]);
    }

    public function test_the_clinic_account_can_change_a_price_through_an_update(): void
    {
        $visitType = $this->clinic->visitTypes()->first();
        $visitType->update(['price' => 300]);

        Sanctum::actingAs($this->secretary);

        $this->putJson(route('api.v1.visit-types.update', $visitType), [
            'name' => 'كشف',
            'duration_minutes' => 25,
            'price' => 1,
        ])->assertOk();

        $visitType->refresh();

        $this->assertSame(25, $visitType->duration_minutes);
        $this->assertEquals(1, (float) $visitType->price);
    }

    public function test_duplicate_active_names_are_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.visit-types.store'), [
            'name' => 'كشف',
            'duration_minutes' => 20,
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VISIT_TYPE_DUPLICATE_NAME');
    }

    public function test_a_hidden_type_does_not_block_reusing_its_name(): void
    {
        VisitType::factory()->hidden()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'سونار',
        ]);

        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.visit-types.store'), [
            'name' => 'سونار',
            'duration_minutes' => 15,
        ])->assertCreated();
    }

    public function test_deleting_hides_rather_than_removes_the_row(): void
    {
        $visitType = $this->clinic->visitTypes()->first();

        Sanctum::actingAs($this->owner);

        $this->deleteJson(route('api.v1.visit-types.hide', $visitType))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('visit_types', [
            'id' => $visitType->id,
            'is_active' => false,
        ]);
    }

    public function test_the_last_active_visit_type_cannot_be_hidden(): void
    {
        Sanctum::actingAs($this->owner);

        $types = $this->clinic->visitTypes()->where('is_active', true)->get();
        $last = $types->pop();

        foreach ($types as $type) {
            $this->deleteJson(route('api.v1.visit-types.hide', $type))->assertOk();
        }

        $this->deleteJson(route('api.v1.visit-types.hide', $last))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VISIT_TYPE_LAST_ACTIVE');

        $this->assertTrue($last->refresh()->is_active);
    }

    public function test_another_clinics_visit_type_is_not_reachable(): void
    {
        $foreign = $this->otherClinic()->visitTypes()->first();

        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.visit-types.update', $foreign), [
            'name' => 'مسروق',
            'duration_minutes' => 20,
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VISIT_TYPE_NOT_FOUND');

        $this->assertDatabaseMissing('visit_types', ['name' => 'مسروق']);
    }

    public function test_it_validates_the_payload(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.visit-types.store'), [
            'name' => '',
            'duration_minutes' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['name', 'duration_minutes']]]);
    }
}
