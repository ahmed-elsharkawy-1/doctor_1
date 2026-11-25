<?php

namespace App\Providers;

use App\Services\Messaging\CloudApiMessageSender;
use App\Services\Messaging\LogMessageSender;
use App\Services\Messaging\LogOtpSender;
use App\Services\Messaging\MessageSender;
use App\Services\Messaging\OtpSender;
use App\Services\Messaging\ZadxOtpSender;
use App\Services\Settings\SystemSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MessageSender::class, function () {
            return match (config('clinic.messaging.driver')) {
                'cloud_api' => new CloudApiMessageSender,
                default => new LogMessageSender,
            };
        });

        // One-time codes for patient self-booking. Same shape as above, and
        // for the same reason: the flow has to work end to end before Meta
        // approves an authentication template.
        $this->app->bind(OtpSender::class, function () {
            return match (config('clinic.self_booking.otp.driver')) {
                'zadx' => new ZadxOtpSender,
                default => new LogOtpSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A waiting-room TV reloads its board every few seconds (ELA-19).
        // Counted per screen, not per address: a plain `throttle:` shares one
        // counter per IP with the reports login and reviews, and a clinic's
        // TVs sit behind the same address as its doctor.
        RateLimiter::for('tv', fn (Request $request) => Limit::perMinute(20)->by('tv|'.$request->route('token')));

        // Project settings saved on the admin page sit over .env. A worker
        // boots once and lives for hours, so it re-reads before each job.
        //
        // Never while the config is being cached: every container runs
        // `artisan optimize` as it starts, Laravel builds that cache by booting
        // the app, and saved values would be baked in and outlive their row.
        $settings = $this->app->make(SystemSettings::class);

        if (! $this->app->runningConsoleCommand('config:cache', 'optimize')) {
            $settings->apply();
        }

        Event::listen(JobProcessing::class, fn () => $settings->apply());
    }
}
