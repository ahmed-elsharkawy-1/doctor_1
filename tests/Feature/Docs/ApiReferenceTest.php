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
            ->assertSee(route('docs.api.spec'), escape: false);
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
        $this->assertArrayHasKey('/queue', $spec['paths']);
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
    }

    public function test_it_needs_no_login(): void
    {
        $this->assertGuest();

        $this->get(route('docs.api'))->assertOk();
    }
}
