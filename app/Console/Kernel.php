<?php

namespace App\Console;

use App\Models\OTPVerification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // تنظيف OTPs المنتهية يومياً في 3 صباحاً
        $schedule->call(function () {
            $deleted = OTPVerification::cleanup();
            Log::info("OTP Cleanup: {$deleted} records deleted");
        })->daily()->at('03:00');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}