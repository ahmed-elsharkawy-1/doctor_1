<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform's own landing page, at the bare domain.
 *
 * It speaks to doctors deciding whether to use the platform; patients reach
 * their doctor through the doctor's own page and never need this one.
 */
class SiteHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'clinic.site.whatsapp' => null,
            'clinic.site.phone' => null,
            'clinic.site.email' => null,
            'clinic.site.social' => [],
            'clinic.site.show_login' => false,
        ]);
    }

    public function test_the_root_is_the_platform_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            // As read: the highlighted word sits inside its own tag.
            ->assertSeeText(__('site.hero.title'))
            ->assertSee('id="features"', escape: false)
            ->assertSee('id="screens"', escape: false)
            ->assertSee('id="faq"', escape: false)
            ->assertSee('id="contact"', escape: false);
    }

    /** One section: every problem a doctor knows, beside what solves it. */
    public function test_each_problem_sits_beside_its_solution(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="problem-title"', $html);
        $this->assertSame(4, substr_count($html, 'class="pair reveal"'));

        foreach (__('site.features.items') as $item) {
            $this->assertStringContainsString(e($item['problem']), $html);
            $this->assertStringContainsString(e($item['title']), $html);

            foreach ($item['tags'] as $tag) {
                $this->assertStringContainsString(e($tag), $html);
            }
        }
    }

    /** Each role is shown, not just listed: a small picture of what that person sees. */
    public function test_every_role_has_its_own_visual(): void
    {
        $html = $this->get('/')->getContent();

        foreach (array_keys(__('site.roles.tabs')) as $role) {
            $this->assertStringContainsString('class="role-visual role-visual-'.$role.'"', $html);
        }

        // The doctor's own portrait, the one doctors' pages already use.
        $this->assertStringContainsString('images/avatars/female.png', $html);
    }

    /** The secretary's queue speaks the app's own words for each status. */
    public function test_the_queue_preview_uses_the_real_status_names(): void
    {
        $response = $this->get('/');

        foreach ([BookingStatus::WITH_DOCTOR, BookingStatus::ARRIVED, BookingStatus::BOOKED] as $status) {
            $response->assertSee($status->label());
        }
    }

    /** No camera notch on the phone frames: it covered the app's logo on every screen. */
    public function test_the_phone_frames_have_no_notch_over_the_screen(): void
    {
        $this->get('/')->assertDontSee('.phone::before', escape: false);
    }

    /** The hero's one highlighted word — the promise in a glance. */
    public function test_the_hero_highlights_its_key_word(): void
    {
        $this->get('/')->assertSee('<em>'.e(__('site.hero.title_highlight')).'</em>', escape: false);
    }

    /** Each screen says in a line what it shows. */
    public function test_every_screen_has_a_caption_and_a_line_about_it(): void
    {
        $response = $this->get('/');

        foreach (array_keys(config('clinic.site.screens')) as $key) {
            $response->assertSee(__('site.screens.items.'.$key))
                ->assertSee(__('site.screens.notes.'.$key));
        }
    }

    /** Beside the questions: somewhere to go when the answer is not there. */
    public function test_the_faq_offers_a_way_to_ask(): void
    {
        $this->get('/')
            ->assertSee(__('site.faq.help_title'))
            ->assertSee('class="faq-help', escape: false);
    }

    /** The opening screen: the white mark on the brand blue. */
    public function test_it_opens_on_the_logo(): void
    {
        $this->get('/')
            ->assertSee('id="splash"', escape: false)
            ->assertSee(config('clinic.brand.logo_white'), escape: false);
    }

    /**
     * The opening screen can never trap the page: CSS removes it on its own
     * even if no script runs, and a visitor sees it once per visit.
     */
    public function test_the_opening_screen_always_goes_away(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('@keyframes splash-failsafe', $html);
        $this->assertMatchesRegularExpression('/\.splash \{[^}]*animation:[^}]*splash-failsafe/s', $html);
        $this->assertStringContainsString("sessionStorage.getItem('splash-seen')", $html);
        // Nothing to animate for a visitor who asked for less motion.
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)[^{]*\{[^@]*\.splash/s', $html);
    }

    /** Every class the page uses is styled — removing a section must not take a shared style with it. */
    public function test_the_shared_styles_the_page_relies_on_are_defined(): void
    {
        $html = $this->get('/')->getContent();

        foreach (['.tile', '.pair', '.panel', '.phone', '.btn', '.head', '.section'] as $selector) {
            $this->assertMatchesRegularExpression('/^\s*'.preg_quote($selector, '/').'\s*\{/m', $html, "No style for {$selector}.");
        }
    }

    /** The roles section is the page's one dark band. */
    public function test_the_roles_sit_on_the_dark_band(): void
    {
        $this->get('/')->assertSee('class="section dark" id="roles"', escape: false);
    }

    /** The clinic web app is not in use yet, so the page does not send anyone there. */
    public function test_the_clinic_sign_in_is_hidden_by_default(): void
    {
        $this->get('/')
            ->assertDontSee(route('app.login'), escape: false)
            ->assertDontSee(__('site.nav.login'));
    }

    public function test_the_clinic_sign_in_returns_when_switched_on(): void
    {
        config(['clinic.site.show_login' => true]);

        $this->get('/')
            ->assertSee(route('app.login'), escape: false)
            ->assertSee(__('site.nav.login'));
    }

    /** Unlike the old signpost, this page is meant to be found. */
    public function test_it_is_indexable_and_shareable_on_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('/')
            ->assertOk()
            ->assertDontSee('noindex', escape: false)
            ->assertSee('<link rel="canonical"', escape: false)
            ->assertSee('property="og:title"', escape: false)
            ->assertSee('name="description"', escape: false);
    }

    /** A button that opens a chat with nobody is worse than no button. */
    public function test_whatsapp_appears_only_once_a_number_is_set(): void
    {
        $this->get('/')
            ->assertDontSee('wa.me', escape: false)
            ->assertDontSee(__('site.hero.whatsapp'))
            ->assertDontSee('class="wa-float"', escape: false);

        config(['clinic.site.whatsapp' => '01012345678']);

        $this->get('/')->assertSee('https://wa.me/201012345678', escape: false);
    }

    public function test_a_number_that_cannot_be_dialled_is_left_out(): void
    {
        config(['clinic.site.whatsapp' => '123']);

        $this->get('/')->assertOk()->assertDontSee('wa.me', escape: false);
    }

    public function test_contact_details_follow_the_config(): void
    {
        config([
            'clinic.site.phone' => '01112223334',
            'clinic.site.email' => 'hello@example.test',
            'clinic.site.social' => ['facebook' => 'https://facebook.com/example-page'],
        ]);

        $this->get('/')
            ->assertSee('tel:+201112223334', escape: false)
            ->assertSee('mailto:hello@example.test', escape: false)
            ->assertSee('https://facebook.com/example-page', escape: false);
    }

    /** One way in: doctors reach the team directly. No form, and nothing aimed at patients yet. */
    public function test_contact_is_direct_with_no_form_and_no_patient_note(): void
    {
        config([
            'clinic.site.whatsapp' => '01001600581',
            'clinic.site.phone' => '01001600581',
            'clinic.site.email' => 'team@example.test',
        ]);

        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('/contact', $html);
        $this->assertSame(3, preg_match_all('/class="channel( channel-wa)?"/', $html));
        $this->assertStringContainsString('https://wa.me/201001600581', $html);
        $this->assertStringContainsString('tel:+201001600581', $html);
        $this->assertStringContainsString('mailto:team@example.test', $html);
        // Doctors only for now: nothing on the page is addressed to patients.
        $this->assertStringNotContainsString('patient-note', $html);
    }

    /** Each channel is an action (open the app) plus a copy button — the raw value is not printed as text. */
    public function test_each_channel_opens_its_app_and_can_be_copied(): void
    {
        config([
            'clinic.site.whatsapp' => '01001600581',
            'clinic.site.phone' => '01001600581',
            'clinic.site.email' => 'team@example.test',
        ]);

        $response = $this->get('/')
            ->assertSee(__('site.contact.whatsapp_action'))
            ->assertSee(__('site.contact.phone_action'))
            ->assertSee(__('site.contact.email_action'));

        $html = $response->getContent();

        // What the copy buttons put on the clipboard: the number as dialled
        // here, and the address.
        $this->assertSame(2, substr_count($html, 'data-copy="01001600581"'));
        $this->assertSame(1, substr_count($html, 'data-copy="team@example.test"'));

        // Not printed as text anywhere on the page — the cards or the footer.
        $this->assertDoesNotMatchRegularExpression('/>\s*01001600581\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*team@example\.test\s*</', $html);
    }

    /** A channel left empty is simply not offered. */
    public function test_only_the_channels_that_are_set_are_shown(): void
    {
        config(['clinic.site.email' => 'team@example.test']);

        $html = $this->get('/')->getContent();

        $this->assertSame(1, preg_match_all('/class="channel( channel-wa)?"/', $html));
        $this->assertStringNotContainsString('tel:', $html);
    }

    /** The screens are files someone will replace; a wrong name would ship a broken image. */
    public function test_every_screen_it_shows_exists(): void
    {
        $screens = config('clinic.site.screens');

        $this->assertNotEmpty($screens);

        foreach ($screens as $key => $path) {
            $this->assertFileExists(public_path($path), "Screen [{$key}] points at a missing file.");
        }
    }
}
