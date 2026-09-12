<?php

namespace Tests\Feature\Web;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The platform's own branding, as a visitor meets it.
 *
 * Every path comes from config('clinic.brand'), so replacing the artwork is a
 * matter of dropping new files into public/images/brand. These tests hold two
 * things: that the files the config names actually exist, and that every page
 * a patient can reach carries them.
 */
class BrandAssetsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['slug' => 'dr-sara']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A config key pointing at a file nobody shipped would fail silently as a
     * broken image, which is exactly the kind of thing nobody notices.
     */
    public function test_every_branded_file_the_config_names_exists(): void
    {
        foreach (['logo', 'logo_white', 'cover', 'favicon', 'apple_touch_icon', 'icon_192'] as $key) {
            $path = public_path(config("clinic.brand.{$key}"));

            $this->assertFileExists($path, "clinic.brand.{$key} points at a file that is not there.");
            $this->assertGreaterThan(0, filesize($path));
        }
    }

    public function test_the_public_doctor_page_carries_the_icons(): void
    {
        $this->get('/dr-sara')
            ->assertOk()
            ->assertSee(config('clinic.brand.favicon'), escape: false)
            ->assertSee(config('clinic.brand.apple_touch_icon'), escape: false)
            ->assertSee(config('clinic.brand.color'), escape: false);
    }

    /**
     * A doctor with a portrait shares their own face; everyone else shares the
     * platform cover rather than nothing at all.
     */
    public function test_the_cover_is_the_share_image_until_a_doctor_has_a_portrait(): void
    {
        $this->get('/dr-sara')
            ->assertOk()
            ->assertSee(config('clinic.brand.cover'), escape: false);

        $this->clinic->doctor->update(['photo_path' => 'doctors/1/portrait.png']);

        $this->get('/dr-sara')
            ->assertOk()
            ->assertSee('doctors/1/portrait.png', escape: false)
            ->assertDontSee(config('clinic.brand.cover'), escape: false);
    }

    public function test_the_patient_pages_carry_the_icons(): void
    {
        $booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-12 11:00', $this->clinic->timezone))
            ->create();

        foreach ([$booking->trackingUrl(), $booking->reviewUrl()] as $url) {
            $this->get($url)->assertOk()->assertSee(config('clinic.brand.favicon'), escape: false);
        }
    }

    public function test_the_root_signpost_shows_the_mark(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(config('clinic.brand.logo'), escape: false);
    }
}
