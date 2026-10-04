<?php

namespace Tests\Feature\Reports;

use App\Models\Booking;
use App\Models\ReportDelivery;
use App\Models\User;
use App\Services\Messaging\MessageSender;
use App\Services\V1\Reports\ReportDeliveryService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingMessageSender;
use Tests\TestCase;

/**
 * The doctor's morning WhatsApp: one message, to the right people, once.
 */
class ReportDeliveryTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private RecordingMessageSender $sender;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('2026-10-04'); // a Sunday

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);
        $this->clinic->update(['timezone' => 'Africa/Cairo', 'name' => 'عيادة الاختبار', 'reports_enabled' => true]);

        $this->doctor = $this->owner;
        $this->doctor->update(['can_access_reports' => true, 'phone' => '+201001234567']);

        $this->sender = new RecordingMessageSender;
        $this->app->instance(MessageSender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Which report, which morning
    |--------------------------------------------------------------------------
    */

    public function test_an_ordinary_morning_sends_yesterday(): void
    {
        Booking::factory()->forClinic($this->clinic)->at(Carbon::parse('2026-10-03 10:00', 'Africa/Cairo'))->done()->create(['price' => 940]);

        $this->morning();

        $this->assertCount(1, $this->sender->templates);
        $sent = $this->sender->templates[0];

        $this->assertSame('clinic_report', $sent['template']);
        $this->assertSame('+201001234567', $sent['to']);
        $this->assertSame('day/2026-10-03', $sent['button']);
        $this->assertStringContainsString('السبت', $sent['variables'][0]);
        $this->assertStringContainsString('أكتوبر', $sent['variables'][0]);
        $this->assertSame('عيادة الاختبار', $sent['variables'][1]);
        $this->assertSame('1', $sent['variables'][2]);
        $this->assertStringContainsString('940', $sent['variables'][3]);
    }

    public function test_saturday_sends_last_week(): void
    {
        $this->at('2026-10-03');

        $this->morning();

        $this->assertSame('week/2026-09-26', $this->sender->templates[0]['button']);
    }

    public function test_the_first_sends_last_month_even_on_a_saturday(): void
    {
        $this->at('2026-08-01'); // a Saturday and the 1st

        $this->morning();

        $this->assertCount(1, $this->sender->templates);
        $this->assertSame('month/2026-07', $this->sender->templates[0]['button']);
    }

    /*
    |--------------------------------------------------------------------------
    | Who gets it
    |--------------------------------------------------------------------------
    */

    public function test_only_clinics_with_reports_switched_on(): void
    {
        $this->clinic->update(['reports_enabled' => false]);

        $this->morning();

        $this->assertSame([], $this->sender->templates);
    }

    /** The report carries income and patients' phones: flagged accounts only. */
    public function test_only_flagged_active_accounts_with_a_phone(): void
    {
        $this->secretary->update(['phone' => '+201009876543']); // not flagged
        User::factory()->secretary()->inClinic($this->clinic)->create(['can_access_reports' => true, 'phone' => null]);
        User::factory()->secretary()->inactive()->inClinic($this->clinic)->create(['can_access_reports' => true, 'phone' => '+201111111111']);

        $this->morning();

        $this->assertSame(['+201001234567'], array_column($this->sender->templates, 'to'));
    }

    public function test_another_clinics_accounts_never_get_this_clinics_report(): void
    {
        $other = $this->otherClinic();
        User::factory()->secretary()->inClinic($other)->create(['can_access_reports' => true, 'phone' => '+201222222222']);

        app(ReportDeliveryService::class)->deliver($this->clinic, 'day', '2026-10-03');

        $this->assertSame(['+201001234567'], array_column($this->sender->templates, 'to'));
    }

    /** It goes to the doctor, so the patient WhatsApp switch has no say. */
    public function test_it_is_sent_with_the_patient_whatsapp_switch_off(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);

        $this->morning();

        $this->assertCount(1, $this->sender->templates);
    }

    /*
    |--------------------------------------------------------------------------
    | Once, and only once
    |--------------------------------------------------------------------------
    */

    public function test_a_report_is_never_sent_twice(): void
    {
        $this->morning();
        $this->morning();
        app(ReportDeliveryService::class)->deliver($this->clinic, 'day', '2026-10-03');

        $this->assertCount(1, $this->sender->templates);
        $this->assertSame('sent', ReportDelivery::sole()->status);
    }

    public function test_an_explicit_resend_sends_again(): void
    {
        $this->morning();

        app(ReportDeliveryService::class)->deliver($this->clinic, 'day', '2026-10-03', force: true);

        $this->assertCount(2, $this->sender->templates);
        $this->assertSame(1, ReportDelivery::count());
    }

    public function test_a_failed_send_is_recorded_and_can_be_sent_again(): void
    {
        $this->sender->failWith = 'Template not approved';

        try {
            $this->morning();
        } catch (RuntimeException) {
        }

        $delivery = ReportDelivery::sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('Template not approved', $delivery->error);

        // A failure is not "already sent": the next attempt goes through.
        $this->sender->failWith = null;
        app(ReportDeliveryService::class)->deliver($this->clinic, 'day', '2026-10-03');

        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_a_period_the_page_will_not_open_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ReportDeliveryService::class)->deliver($this->clinic, 'day', '2026-10-04'); // today
    }

    /*
    |--------------------------------------------------------------------------
    | The schedule
    |--------------------------------------------------------------------------
    */

    public function test_it_runs_every_morning_at_the_configured_time_in_cairo(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'clinic:send-reports'));

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('Africa/Cairo', $event->timezone);
    }

    private function morning(): void
    {
        $this->artisan('clinic:send-reports')->assertSuccessful();
    }

    private function at(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 09:00', 'Africa/Cairo'));
    }
}
