<?php

namespace App\Providers;

use App\Services\Messaging\CloudApiMessageSender;
use App\Services\Messaging\LogMessageSender;
use App\Services\Messaging\LogOtpSender;
use App\Services\Messaging\MessageSender;
use App\Services\Messaging\OtpSender;
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
                default => new LogOtpSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
