<?php

namespace Tests\Feature\Api\V1\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * `description` is the line under each service on the clinic's public page.
 * It was added after the mobile client shipped, so the API has to behave well
 * for a caller that has never heard of it.
 */
class VisitTypeDescriptionTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        Sanctum::actingAs($this->owner);
    }

    public function test_an_update_that_omits_the_description_leaves_it_alone(): void
    {
        $visitType = $this->clinic->visitTypes()->first();
        $visitType->update(['description' => 'أول زيارة لتحديد الحالة']);

        // Exactly what today's mobile client sends.
        $this->putJson(route('api.v1.visit-types.update', $visitType), [
            'name' => $visitType->name,
            'duration_minutes' => $visitType->duration_minutes,
            'price' => 400,
        ])->assertOk();

        $this->assertSame('أول زيارة لتحديد الحالة', $visitType->fresh()->description);
    }

    public function test_a_description_can_be_set_and_read_back(): void
    {
        $visitType = $this->clinic->visitTypes()->first();

        $this->putJson(route('api.v1.visit-types.update', $visitType), [
            'name' => $visitType->name,
            'duration_minutes' => $visitType->duration_minutes,
            'description' => 'زيارة متابعة قصيرة',
        ])
            ->assertOk()
            ->assertJsonPath('data.description', 'زيارة متابعة قصيرة');

        $this->assertSame('زيارة متابعة قصيرة', $visitType->fresh()->description);
    }

    public function test_an_empty_description_clears_it(): void
    {
        $visitType = $this->clinic->visitTypes()->first();
        $visitType->update(['description' => 'نص قديم']);

        $this->putJson(route('api.v1.visit-types.update', $visitType), [
            'name' => $visitType->name,
            'duration_minutes' => $visitType->duration_minutes,
            'description' => '',
        ])->assertOk();

        $this->assertNull($visitType->fresh()->description);
    }

    public function test_the_field_is_optional_on_create(): void
    {
        $this->postJson(route('api.v1.visit-types.store'), [
            'name' => 'سونار',
            'duration_minutes' => 15,
        ])
            ->assertCreated()
            ->assertJsonPath('data.description', null);
    }
}
