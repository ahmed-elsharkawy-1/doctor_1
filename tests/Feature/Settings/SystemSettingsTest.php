<?php

namespace Tests\Feature\Settings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Patient;
use App\Models\SystemSetting;
use App\Providers\AppServiceProvider;
use App\Services\Settings\SystemSettings;
use App\Services\V1\Patients\OnlineBookingService;
use App\Services\V1\Queue\BookingStatusService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Project settings saved on the admin page sit over the matching .env
 * values: until a key is saved, .env decides; once saved, the saved value is
 * what every part of the app reads — web requests, queued jobs, the
 * scheduler — without a restart.
 */
class SystemSettingsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private function settings(): SystemSettings
    {
        return app(SystemSettings::class);
    }

    public function test_an_unsaved_key_keeps_its_env_value(): void
    {
        config(['clinic.self_booking.no_show_block.threshold' => 2]);

        $this->settings()->apply();

        $this->assertSame(2, config('clinic.self_booking.no_show_block.threshold'));
    }

    public function test_a_saved_key_is_what_the_app_reads(): void
    {
        $this->settings()->set('self_booking.no_show_block.threshold', 3);

        $this->assertSame(3, config('clinic.self_booking.no_show_block.threshold'));
    }

    /** A value saved by another process (the web) reaches a worker before its next job. */
    public function test_a_queued_job_reads_the_latest_saved_values(): void
    {
        SystemSetting::create(['key' => 'turn_alert.first_patients_lead_minutes', 'value' => 30]);
        $this->app['cache']->forget('system_settings');
        config(['clinic.turn_alert.first_patients_lead_minutes' => 15]);

        event(new JobProcessing('database', $this->createMock(Job::class)));

        $this->assertSame(30, config('clinic.turn_alert.first_patients_lead_minutes'));
    }

    public function test_values_are_stored_as_their_type(): void
    {
        $this->settings()->set('self_booking.show_price', true);
        $this->settings()->set('reports.send_at', '07:30');

        $this->assertTrue(config('clinic.self_booking.show_price'));
        $this->assertSame('07:30', config('clinic.reports.send_at'));
    }

    /** A social link cleared on the page disappears, as an empty .env value does. */
    public function test_an_emptied_social_link_is_dropped(): void
    {
        config(['clinic.site.social' => ['facebook' => 'https://facebook.com/elayadah']]);

        $this->settings()->set('site.social.facebook', null);
        $this->settings()->set('site.social.instagram', 'https://instagram.com/elayadah');

        $this->assertSame(['instagram' => 'https://instagram.com/elayadah'], config('clinic.site.social'));
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->settings()->set('messaging.driver', 'log');
    }

    /** The rule itself follows: with three needed, two no-shows no longer block. */
    public function test_the_no_show_rule_follows_the_saved_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'Africa/Cairo'));
        $this->setUpClinic();
        $this->clinic->update(['no_show_block_enabled' => true]);
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);

        $this->settings()->set('self_booking.no_show_block.threshold', 3);

        foreach (['2026-11-02', '2026-11-09'] as $date) {
            $booking = Booking::factory()->forClinic($this->clinic)
                ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
                ->create(['patient_id' => $patient->id]);
            app(BookingStatusService::class)->update($booking, BookingStatus::NO_SHOW);
        }

        $this->assertFalse(app(OnlineBookingService::class)->isBlocked($patient->fresh()));

        Carbon::setTestNow();
    }

    /** The public site shows a contact saved on the page. */
    public function test_the_public_site_shows_a_saved_contact(): void
    {
        $this->settings()->set('site.email', 'hello@elayadah.test');

        $this->get('/')->assertOk()->assertSee('hello@elayadah.test');
    }

    /**
     * Containers cache their config when they start (`artisan optimize`).
     * Laravel builds that cache by booting the app, so saved values must not
     * be applied then — or they are baked in and outlive their row.
     */
    public function test_saved_values_are_not_baked_into_the_cached_config(): void
    {
        $this->settings()->set('reports.send_at', '07:45');

        $boot = function (string $command): string {
            config(['clinic.reports.send_at' => '09:00']);
            $_SERVER['argv'] = ['artisan', $command];

            (new AppServiceProvider($this->app))->boot();

            return config('clinic.reports.send_at');
        };

        $argv = $_SERVER['argv'];

        try {
            $this->assertSame('09:00', $boot('config:cache'));
            $this->assertSame('09:00', $boot('optimize'));

            // Any other command — the scheduler's per-minute run — applies it.
            $this->assertSame('07:45', $boot('schedule:run'));
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }
}
