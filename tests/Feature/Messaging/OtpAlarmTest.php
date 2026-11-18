<?php

namespace Tests\Feature\Messaging;

use App\Mail\OtpAlarmMail;
use App\Models\Clinic;
use App\Models\MessageTemplate;
use App\Models\OtpAlarm;
use App\Models\PhoneVerification;
use App\Services\Messaging\MessageSender;
use App\Services\Messaging\OtpAlarmMonitor;
use App\Services\Settings\SystemSettings;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\Support\RecordingMessageSender;
use Tests\TestCase;

/**
 * Codes sent and nobody typing them: the SMS provider is failing silently.
 * On 17 November 2026 it showed a whole day of such codes as "Delivered",
 * and patients found out first. This alarm is what finds out now.
 */
class OtpAlarmTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    private RecordingMessageSender $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00'));
        Mail::fake();

        $this->clinic = Clinic::factory()->create(['name' => 'Dr Seham']);

        $this->seed(MessageTemplateSeeder::class);
        $this->whatsapp = new RecordingMessageSender;
        $this->app->instance(MessageSender::class, $this->whatsapp);

        config([
            'clinic.otp_alarm.enabled' => true,
            'clinic.otp_alarm.emails' => 'ahmed@elayadah.test, saad@elayadah.test',
            'clinic.otp_alarm.whatsapp_numbers' => '01000000001, +201000000002',
            'clinic.otp_alarm.min_codes' => 5,
            'clinic.otp_alarm.min_phones' => 3,
            'clinic.otp_alarm.window_minutes' => 60,
            'clinic.otp_alarm.grace_minutes' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Codes sent at these minutes before now, one number per code unless
     * $phones says how many numbers to spread them over.
     *
     * @param  list<int>  $minutesAgo
     */
    private function codes(array $minutesAgo, ?int $phones = null, array $state = [], string $prefix = '+2010000000'): void
    {
        foreach ($minutesAgo as $i => $ago) {
            $at = Carbon::now()->subMinutes($ago);

            PhoneVerification::factory()
                ->forPhone($this->clinic, $prefix.($phones === null ? $i : $i % $phones))
                ->create($state + ['created_at' => $at, 'updated_at' => $at, 'sent_at' => $at]);
        }
    }

    private function check(): ?OtpAlarm
    {
        return app(OtpAlarmMonitor::class)->check();
    }

    public function test_codes_nobody_types_raise_the_alarm_and_mail_it(): void
    {
        $this->codes([50, 40, 30, 20, 10]);

        $alarm = $this->check();

        $this->assertNotNull($alarm);
        $this->assertSame('2026-11-17 09:10:00', $alarm->started_at->toDateTimeString());
        $this->assertSame([5, 5, 0], [$alarm->codes, $alarm->phones, $alarm->refused]);
        $this->assertSame([['name' => 'Dr Seham', 'codes' => 5]], $alarm->details['clinics']);
        $this->assertNotNull($alarm->emailed_at);

        Mail::assertSent(OtpAlarmMail::class, fn (OtpAlarmMail $mail): bool => ! $mail->recovered
            && $mail->hasTo('ahmed@elayadah.test')
            && $mail->hasTo('saad@elayadah.test'));
    }

    public function test_codes_being_typed_raise_nothing(): void
    {
        $this->codes([50, 40, 30, 20]);
        $this->codes([10], state: ['verified_at' => Carbon::now()->subMinutes(9)]);

        $this->assertNull($this->check());
        $this->assertSame(0, OtpAlarm::count());
        Mail::assertNothingSent();
    }

    /** A code typed in the window counts even if it was sent before it. */
    public function test_an_older_code_typed_within_the_hour_means_codes_arrive(): void
    {
        $this->codes([50, 40, 30, 20, 10]);
        $this->codes([70], state: ['verified_at' => Carbon::now()->subMinutes(55)]);

        $this->assertNull($this->check());
    }

    public function test_too_few_codes_or_numbers_raise_nothing(): void
    {
        $this->codes([50, 40, 30, 20]);
        $this->assertNull($this->check(), 'Four codes are below five.');

        PhoneVerification::query()->delete();

        // Six codes, but only two numbers: one or two patients asking again.
        $this->codes([50, 45, 40, 30, 20, 10], phones: 2);
        $this->assertNull($this->check(), 'Two numbers are below three.');
    }

    /** The patient may still be reading a code a few minutes old. */
    public function test_codes_younger_than_the_grace_period_are_not_counted(): void
    {
        $this->codes([50, 40, 30, 20, 3]);

        $this->assertNull($this->check());
    }

    public function test_codes_older_than_the_window_are_not_counted(): void
    {
        $this->codes([70, 40, 30, 20, 10]);

        $this->assertNull($this->check());
    }

    /** Refused sends left patients stuck too; they count, and the mail says so. */
    public function test_codes_the_provider_refused_count_and_are_reported(): void
    {
        $this->codes([50, 40, 30], state: ['sent_at' => null]);
        $this->codes([20, 10], prefix: '+2011000000');

        $alarm = $this->check();

        $this->assertSame(5, $alarm->codes);
        $this->assertSame(3, $alarm->refused);
    }

    /** One mail when it starts, nothing more while it lasts. */
    public function test_an_open_alarm_does_not_mail_again(): void
    {
        $this->codes([50, 40, 30, 20, 10]);
        $first = $this->check();

        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->codes([20, 10], prefix: '+2011000000');

        $this->assertTrue($first->is($this->check()));
        $this->assertSame(1, OtpAlarm::count());
        Mail::assertSentCount(1);
    }

    public function test_the_first_typed_code_closes_it_and_mails_the_recovery(): void
    {
        $this->codes([50, 40, 30, 20, 10]);
        $alarm = $this->check();

        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->codes([3], state: ['verified_at' => Carbon::now()->subMinutes(2)]);

        $this->assertNull($this->check());

        $alarm->refresh();
        $this->assertSame('2026-11-17 11:58:00', $alarm->ended_at->toDateTimeString());
        $this->assertNotNull($alarm->recovery_sent_at);
        Mail::assertSent(OtpAlarmMail::class, fn (OtpAlarmMail $mail): bool => $mail->recovered);
    }

    /** No address set: still recorded and logged, and mailed once one is. */
    public function test_without_an_address_it_is_logged_and_mailed_once_one_is_set(): void
    {
        config(['clinic.otp_alarm.emails' => '']);
        Log::spy();
        $this->codes([50, 40, 30, 20, 10]);

        $alarm = $this->check();

        $this->assertNull($alarm->emailed_at);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('critical')->once();

        config(['clinic.otp_alarm.emails' => 'ahmed@elayadah.test']);
        $this->check();

        $this->assertNotNull($alarm->fresh()->emailed_at);
        Mail::assertSent(OtpAlarmMail::class, fn (OtpAlarmMail $mail): bool => $mail->hasTo('ahmed@elayadah.test'));
    }

    /** A mail server hiccup must not lose the alarm's mail. */
    public function test_a_failed_mail_is_tried_again_on_the_next_check(): void
    {
        $fake = Mail::getFacadeRoot();
        $broken = Mockery::mock();
        $broken->shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
        Mail::swap($broken);
        $this->codes([50, 40, 30, 20, 10]);

        $alarm = $this->check();
        $this->assertNull($alarm->emailed_at);

        Mail::swap($fake);
        $this->check();

        $this->assertNotNull($alarm->fresh()->emailed_at);
        Mail::assertSentCount(1);

        // WhatsApp went the first time and is not repeated.
        $this->assertCount(2, $this->whatsapp->templates);
    }

    public function test_switched_off_it_raises_nothing(): void
    {
        config(['clinic.otp_alarm.enabled' => false]);
        $this->codes([50, 40, 30, 20, 10]);

        $this->assertNull($this->check());
        $this->assertSame(0, OtpAlarm::count());
    }

    /** The thresholds saved on the settings page are the ones used. */
    public function test_it_follows_the_thresholds_saved_on_the_settings_page(): void
    {
        $this->codes([50, 40, 30, 20, 10]);

        app(SystemSettings::class)->set('otp_alarm.min_codes', 6);
        $this->assertNull($this->check());

        app(SystemSettings::class)->set('otp_alarm.min_codes', 5);
        $this->assertNotNull($this->check());
    }

    public function test_the_mail_is_in_arabic_and_links_to_the_settings_page(): void
    {
        $this->codes([50, 40, 30, 20, 10]);
        $alarm = $this->check();

        $html = (new OtpAlarmMail($alarm, recovered: false))->render();

        $this->assertStringContainsString('كود التأكيد', $html);
        $this->assertStringContainsString('Dr Seham: 5', $html);
        $this->assertStringContainsString('/admin/system-settings', $html);
        $this->assertStringNotContainsString('otp_alarm.', $html);

        $alarm->update(['ended_at' => Carbon::now()]);
        $recovered = (new OtpAlarmMail($alarm, recovered: true))->render();
        $this->assertStringContainsString('تصل مرة أخرى', $recovered);
        $this->assertStringNotContainsString('otp_alarm.', $recovered);
    }

    public function test_it_whatsapps_every_alarm_number_with_the_details(): void
    {
        $this->codes([50, 40, 30, 20, 10]);

        $alarm = $this->check();

        $this->assertNotNull($alarm->whatsapped_at);
        $this->assertSame(['+201000000001', '+201000000002'], array_column($this->whatsapp->templates, 'to'));
        $this->assertSame(
            ['template' => 'otp_alarm_started', 'variables' => ['17/11 الساعة 09:10'], 'button' => null],
            array_diff_key($this->whatsapp->templates[0], ['to' => true]),
        );
    }

    public function test_recovery_goes_on_whatsapp_too(): void
    {
        $this->codes([50, 40, 30, 20, 10]);
        $this->check();

        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->codes([3], state: ['verified_at' => Carbon::now()->subMinutes(2)]);
        $this->check();

        $last = end($this->whatsapp->templates);
        $this->assertSame('otp_alarm_recovered', $last['template']);
        $this->assertSame(['17/11 الساعة 11:58'], $last['variables']);
        $this->assertCount(4, $this->whatsapp->templates);
    }

    /** A WhatsApp failure is retried next check; the mail is not sent twice. */
    public function test_a_failed_whatsapp_is_tried_again_without_repeating_the_mail(): void
    {
        $this->whatsapp->failWith = 'Meta is down';
        $this->codes([50, 40, 30, 20, 10]);

        $alarm = $this->check();
        $this->assertNull($alarm->whatsapped_at);
        $this->assertNotNull($alarm->emailed_at);

        $this->whatsapp->failWith = null;
        $this->check();

        $this->assertNotNull($alarm->fresh()->whatsapped_at);
        $this->assertCount(2, $this->whatsapp->templates);
        Mail::assertSentCount(1);
    }

    /** Until Meta approves the template it is switched off, and nothing is tried. */
    public function test_no_whatsapp_without_numbers_or_with_the_template_off(): void
    {
        config(['clinic.otp_alarm.whatsapp_numbers' => '']);
        $this->codes([50, 40, 30, 20, 10]);
        $this->check();
        $this->assertSame([], $this->whatsapp->templates);

        config(['clinic.otp_alarm.whatsapp_numbers' => '01000000001']);
        MessageTemplate::where('key', 'otp_alarm_started')->update(['is_active' => false]);
        $this->check();
        $this->assertSame([], $this->whatsapp->templates);
    }

    /** Lists as people type them: Arabic commas, spaces inside a number. */
    public function test_lists_are_read_as_people_type_them(): void
    {
        config(['clinic.otp_alarm.whatsapp_numbers' => '010 0000 0001، 01000000001; nonsense']);

        $this->assertSame(['+201000000001'], app(OtpAlarmMonitor::class)->whatsAppNumbers());
    }

    public function test_the_window_and_grace_come_from_the_settings_page(): void
    {
        $this->codes([50, 40, 30, 20, 10]);

        app(SystemSettings::class)->set('otp_alarm.window_minutes', 45);
        $this->assertNull($this->check(), 'Only four codes in the last 45 minutes.');

        app(SystemSettings::class)->set('otp_alarm.window_minutes', 60);
        app(SystemSettings::class)->set('otp_alarm.grace_minutes', 15);
        $this->assertNull($this->check(), 'The 10-minute-old code is inside a 15-minute grace.');

        app(SystemSettings::class)->set('otp_alarm.grace_minutes', 5);
        $this->assertNotNull($this->check());
    }

    public function test_the_command_runs_the_check_and_is_scheduled(): void
    {
        $this->codes([50, 40, 30, 20, 10]);

        $this->artisan('clinic:check-otp-delivery')->assertSuccessful();

        $this->assertSame(1, OtpAlarm::count());

        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event): bool => str_contains((string) $event->command, 'clinic:check-otp-delivery'));
        $this->assertTrue($scheduled);
    }
}
