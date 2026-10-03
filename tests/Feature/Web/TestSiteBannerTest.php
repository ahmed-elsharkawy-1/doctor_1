<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Staging says what it is on every page a person can land on.
 *
 * It is a full copy of the real site with no password in front of it. A
 * patient handed the wrong link must be able to tell, before booking, that
 * nothing here reaches a clinic.
 */
class TestSiteBannerTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['slug' => 'demo-clinic', 'self_booking_enabled' => true]);
    }

    public function test_every_kind_of_page_carries_it_on_staging(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->get('/')->assertSee(__('app.test_site_banner'));
        $this->get('/demo-clinic')->assertSee(__('app.test_site_banner'));
        $this->get('/app/login')->assertSee(__('app.test_site_banner'));
        $this->get('/demo-clinic/book')->assertSee(__('app.test_site_banner'));
    }

    public function test_production_never_shows_it(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('/')->assertDontSee(__('app.test_site_banner'));
        $this->get('/demo-clinic')->assertDontSee(__('app.test_site_banner'));
        $this->get('/app/login')->assertDontSee(__('app.test_site_banner'));
        $this->get('/demo-clinic/book')->assertDontSee(__('app.test_site_banner'));
    }
}
