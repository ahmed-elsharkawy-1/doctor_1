<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The privacy policy at /privacy: the page the app stores link to.
 */
class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'clinic.site.whatsapp' => null,
            'clinic.site.phone' => null,
            'clinic.site.email' => null,
        ]);
    }

    public function test_it_is_in_arabic_by_default(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('dir="rtl"', escape: false)
            ->assertSeeText(__('privacy.title', [], 'ar'))
            ->assertSeeText(trans('privacy.sections.collect.title', [], 'ar'))
            ->assertSeeText(config('clinic.brand.name'));
    }

    public function test_it_switches_to_english(): void
    {
        $this->get('/privacy?lang=en')
            ->assertOk()
            ->assertSee('dir="ltr"', escape: false)
            ->assertSeeText('Privacy Policy')
            ->assertSeeText('Information we collect');
    }

    public function test_both_languages_have_the_same_sections(): void
    {
        $this->assertSame(
            array_keys(trans('privacy.sections', [], 'ar')),
            array_keys(trans('privacy.sections', [], 'en')),
        );
    }

    public function test_it_shows_only_the_contact_details_that_are_set(): void
    {
        $this->get('/privacy')->assertDontSee('id="contact"', escape: false);

        config(['clinic.site.email' => 'hello@example.com']);

        $this->get('/privacy')
            ->assertSee('id="contact"', escape: false)
            ->assertSee('mailto:hello@example.com', escape: false);
    }

    public function test_no_clinic_can_take_the_path(): void
    {
        $this->assertContains('privacy', config('clinic.landing.reserved'));
    }

    public function test_the_site_footer_links_to_it(): void
    {
        $this->get('/')->assertSee(route('privacy'), escape: false);
    }
}
