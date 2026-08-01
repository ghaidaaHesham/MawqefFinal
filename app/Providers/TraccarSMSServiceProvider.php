<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\TraccarSMSService;

class TraccarSMSServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // تسجيل الـ Service في Service Container
        $this->app->singleton(TraccarSMSService::class, function ($app) {
            return new TraccarSMSService();
        });

        // أو يمكنك تسجيله كـ alias
        $this->app->alias(TraccarSMSService::class, 'traccar.sms');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // يمكنك إضافة أي تهيئة هنا
        // مثلاً: تحميل التكوينات أو تسجيل Routes
    }
}