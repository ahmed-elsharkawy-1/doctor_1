<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\RetentionPage;
use App\Filament\Admin\Pages\RevenuePage;
use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Filament\Admin\Resources\Doctors\DoctorResource;
use App\Filament\Admin\Resources\Specialties\SpecialtyResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Booking;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ReportPagesTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The panel discovers classes by namespace. A resource in the wrong
     * namespace is silently invisible, which is exactly how the panel shipped
     * empty in phase 0 — so assert discovery itself, not just the classes.
     */
    public function test_every_resource_and_page_is_registered_with_the_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $registeredResources = $panel->getResources();
        $registeredPages = $panel->getPages();

        foreach ([ClinicResource::class, DoctorResource::class, SpecialtyResource::class, UserResource::class] as $resource) {
            $this->assertContains($resource, $registeredResources, "{$resource} is not discovered by the panel");
        }

        foreach ([RevenuePage::class, RetentionPage::class] as $page) {
            $this->assertContains($page, $registeredPages, "{$page} is not discovered by the panel");
        }
    }

    public function test_the_owner_can_open_the_revenue_page(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-11 09:00', 'Africa/Cairo'))
            ->done()
            ->create(['price' => 300]);

        $this->actingAs($this->owner)
            ->get(RevenuePage::getUrl(panel: 'admin'))
            ->assertOk();
    }

    public function test_the_owner_can_open_the_retention_page(): void
    {
        $this->actingAs($this->owner)
            ->get(RetentionPage::getUrl(panel: 'admin'))
            ->assertOk();
    }

    /**
     * Reports are the doctor's own numbers; a super admin runs the platform,
     * not a practice, and has no clinic of their own.
     */
    public function test_a_super_admin_sees_no_reports(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertFalse(RevenuePage::canAccess());
        $this->assertFalse(RetentionPage::canAccess());
    }

    public function test_a_secretary_sees_no_reports(): void
    {
        $this->actingAs($this->secretary);

        $this->assertFalse(RevenuePage::canAccess());
        $this->assertFalse(RetentionPage::canAccess());
    }

    public function test_the_owner_may_access_reports(): void
    {
        $this->actingAs($this->owner);

        $this->assertTrue(RevenuePage::canAccess());
        $this->assertTrue(RetentionPage::canAccess());
    }

    public function test_a_secretary_is_refused_at_the_url_too(): void
    {
        $this->actingAs($this->secretary)
            ->get(RevenuePage::getUrl(panel: 'admin'))
            ->assertForbidden();
    }
}
