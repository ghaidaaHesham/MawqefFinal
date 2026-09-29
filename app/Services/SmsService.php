<?php

namespace App\Services;

use App\Services\Sms\TraccarSmsService;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function __construct(
        private TraccarSmsService $traccarSms
    ) {}



    /**
     * إرسال رمز OTP
     */
    public function sendOtp(string $phone, string $otp, string $purpose): bool
    {
        $message = $this->buildOtpMessage($otp, $purpose);

        try {
            $this->traccarSms->send($phone, $message);

            Log::info('OTP SMS sent', [
                'phone'   => $phone,
                'purpose' => $purpose,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send OTP SMS', [
                'phone'   => $phone,
                'purpose' => $purpose,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * بناء نص الرسالة
     */
private function buildOtpMessage(string $otp, string $purpose): string
{
    $expiry = config('otp.expiry_minutes', 5);

    $header = match ($purpose) {
        'password_reset'     => 'Password Reset',
        'phone_verification' => 'Phone Verification',
        'login'              => 'Login',
        'register'           => 'Registration',
        default              => 'Verification',
    };

    return "{$header}\n\n"
        . "Your OTP is: {$otp}\n\n"
        . "Valid for {$expiry} minutes\n"
        . "Do not share this code";
}
}