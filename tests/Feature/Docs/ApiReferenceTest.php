<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

class ApiReferenceTest extends TestCase
{
    public function test_the_reference_page_renders(): void
    {
        $this->get(route('docs.api'))
            ->assertOk()
            ->assertSee('redoc', escape: false)
            ->assertSee(route('docs.api.spec'), escape: false)
            ->assertSee(route('docs.api.design-map'), escape: false);
    }

    public function test_the_spec_is_served_as_json(): void
    {
        $spec = $this->getJson(route('docs.api.spec'))
            ->assertOk()
            ->json();

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertNotEmpty($spec['paths']);
    }

    public function test_configured_tags_are_hidden_from_the_public_spec(): void
    {
        $spec = $this->getJson(route('docs.api.spec'))
            ->assertOk()
            ->json();

        $this->assertNotContains('Postpone', array_column($spec['tags'], 'name'));
        $this->assertNotContains('Patients', array_column($spec['tags'], 'name'));
        $this->assertNotContains('Reports', array_column($spec['tags'], 'name'));

        $this->assertArrayNotHasKey('/postpone/candidates', $spec['paths']);
        $this->assertArrayNotHasKey('/postpone', $spec['paths']);
        $this->assertArrayNotHasKey('/rebooking-list', $spec['paths']);
        $this->assertArrayNotHasKey('/patients', $spec['paths']);
        $this->assertArrayNotHasKey('/patients/{patient}', $spec['paths']);
        $this->assertArrayNotHasKey('/reports/revenue', $spec['paths']);
        $this->assertArrayNotHasKey('/reports/retention', $spec['paths']);
        $this->assertArrayHasKey('/bookings/calendar', $spec['paths']);
    }

    /**
     * Arabic lives throughout the descriptions and examples; escaping it would
     * make the rendered page unreadable.
     */
    public function test_arabic_survives_the_json_encoding(): void
    {
        $this->get(route('docs.api.spec'))
            ->assertOk()
            ->assertSee('كشف', escape: false);
    }

    /**
     * The spec is not secret, but publishing a full map of the API should be
     * a deliberate act, not a default.
     */
    public function test_it_is_hidden_when_disabled(): void
    {
        config()->set('clinic.docs.enabled', false);

        $this->get(route('docs.api'))->assertNotFound();
        $this->getJson(route('docs.api.spec'))->assertNotFound();
        $this->get(route('docs.api.design-map'))->assertNotFound();
    }

    public function test_it_needs_no_login(): void
    {
        $this->assertGuest();

        $this->get(route('docs.api'))->assertOk();
    }

    public function test_the_handoff_page_renders_test_access_details(): void
    {
        $this->get(route('docs.api.handoff'))
            ->assertOk()
            ->assertSee('Doctor 1 Developer Handoff')
            ->assertSee('admin@doctor1.test')
            ->assertSee('doctor@doctor1.test')
            ->assertSee(route('docs.api'), escape: false)
            ->assertSee(route('docs.api.design-map'), escape: false)
            ->assertSee(url('/api/v1'), escape: false);
    }

    public function test_the_design_map_page_renders_api_to_figma_links(): void
    {
        $this->get(route('docs.api.design-map'))
            ->assertOk()
            ->assertSee('API v1 to Figma design map')
            ->assertSee('/bookings/calendar')
            ->assertSee('node-id=431-24337', escape: false)
            ->assertSee(route('docs.api.spec'), escape: false);
    }

    public function test_the_handoff_page_is_hidden_when_docs_are_disabled(): void
    {
        config()->set('clinic.docs.enabled', false);

        $this->get(route('docs.api.handoff'))->assertNotFound();
    }
}
