<?php

namespace App\Providers;

use App\Services\Messaging\CloudApiMessageSender;
use App\Services\Messaging\LogMessageSender;
use App\Services\Messaging\MessageSender;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
