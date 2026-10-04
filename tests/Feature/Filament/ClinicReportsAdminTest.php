<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\ReportDelivery;
use App\Models\User;
use App\Services\Messaging\MessageSender;
use Database\Seeders\MessageTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingMessageSender;
use Tests\TestCase;

/**
 * Switching a clinic's reports on, choosing who reads them, and sending one
 * by hand — all super admin only.
 */
class ClinicReportsAdminTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private User $admin;

    private RecordingMessageSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);
        $this->clinic->update(['timezone' => 'Africa/Cairo', 'slug' => 'dr-test', 'phone' => '+201001234567']);

        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create();

        $this->sender = new RecordingMessageSender;
        $this->app->instance(MessageSender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reports_are_off_for_a_clinic_until_switched_on(): void
    {
        $this->assertFalse($this->clinic->fresh()->reports_enabled);

        $this->actingAs($this->admin);

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm(['reports_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->clinic->fresh()->reports_enabled);
    }

    public function test_an_account_is_given_access_to_reports(): void
    {
        $this->assertFalse($this->owner->fresh()->can_access_reports);

        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['record' => $this->owner->getRouteKey()])
            ->fillForm(['can_access_reports' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->owner->fresh()->can_access_reports);
    }

    public function test_a_report_is_sent_by_hand_and_not_twice_unless_asked(): void
    {
        $this->readyToReport();
        $this->actingAs($this->admin);

        $page = Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()]);

        $page->callAction('sendReport', data: ['period' => 'day:2026-10-03', 'resend' => false])->assertHasNoActionErrors();
        $page->callAction('sendReport', data: ['period' => 'day:2026-10-03', 'resend' => false])->assertHasNoActionErrors();

        $this->assertCount(1, $this->sender->templates);

        $page->callAction('sendReport', data: ['period' => 'day:2026-10-03', 'resend' => true])->assertHasNoActionErrors();

        $this->assertCount(2, $this->sender->templates);
        $this->assertSame('sent', ReportDelivery::sole()->status);
    }

    public function test_the_admin_can_preview_a_clinics_report(): void
    {
        $this->clinic->update(['name' => 'عيادة المعاينة']);

        $this->actingAs($this->admin)
            ->get(route('reports.preview', ['clinic' => $this->clinic->id, 'type' => 'day', 'value' => '2026-10-03']))
            ->assertOk()
            ->assertSee('عيادة المعاينة')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Preview is the operator's; a clinic account cannot use it to read another clinic. */
    public function test_only_a_super_admin_can_preview(): void
    {
        $this->owner->update(['can_access_reports' => true]);
        $this->clinic->update(['reports_enabled' => true]);

        $this->actingAs($this->owner)
            ->get(route('reports.preview', ['clinic' => $this->otherClinic()->id, 'type' => 'day', 'value' => '2026-10-03']))
            ->assertForbidden();

        $this->get(route('reports.preview', ['clinic' => $this->clinic->id, 'type' => 'day', 'value' => '2026-10-03']))
            ->assertForbidden();
    }

    private function readyToReport(): void
    {
        $this->clinic->update(['reports_enabled' => true]);
        $this->owner->update(['can_access_reports' => true, 'phone' => '+201001234567']);
    }
}
