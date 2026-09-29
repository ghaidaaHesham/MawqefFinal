<?php

namespace App\Services;

use App\Models\OTPVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OtpService
{
    public function __construct(
        private SmsService $smsService
    ) {}

    /**
     * طلب إرسال OTP
     */
    public function request(
        string $phone,
        string $purpose = OTPVerification::PURPOSE_PASSWORD_RESET
    ): array {
        // 1. التحقق من إمكانية الإرسال
        $canSend = OTPVerification::checkCanSend($phone, $purpose);

        if (!$canSend['allowed']) {
            Log::warning('OTP request rejected', [
                'phone'   => $phone,
                'purpose' => $purpose,
                'reason'  => $canSend['reason'],
                'ip'      => request()->ip(),
            ]);

            return $canSend;
        }

        // 2. إنشاء OTP جديد (يحذف القديم تلقائياً)
        DB::beginTransaction();

        try {
            $otpRecord = OTPVerification::createFor($phone, $purpose);

            // 3. إرسال SMS
            $this->smsService->sendOtp($phone, $otpRecord->otp, $purpose);

            DB::commit();

            Log::info('OTP created and sent', [
                'phone'   => $phone,
                'purpose' => $purpose,
                'otp_id'  => $otpRecord->id,
                'ip'      => request()->ip(),
            ]);

            return [
                'allowed'    => true,
                'otp'        => $otpRecord,
                'expires_at' => $otpRecord->expires_at,
                'expires_in' => config('otp.expiry_minutes', 5),
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create/send OTP', [
                'phone'   => $phone,
                'purpose' => $purpose,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * التحقق من OTP
     */
    public function verify(
        string $phone,
        string $otp,
        string $purpose = OTPVerification::PURPOSE_PASSWORD_RESET
    ): array {
        // 1. البحث عن OTP نشط
        $otpRecord = OTPVerification::findActive($phone, $purpose);

        if (!$otpRecord) {
            Log::warning('OTP not found', [
                'phone'   => $phone,
                'purpose' => $purpose,
                'ip'      => request()->ip(),
            ]);

            return [
                'success' => false,
                'reason'  => 'not_found',
                'message' => 'لا يوجد رمز تحقق نشط، يرجى طلب رمز جديد',
            ];
        }

        // 2. التحقق من انتهاء الصلاحية
        if ($otpRecord->isExpired()) {
            $otpRecord->delete();

            Log::warning('OTP expired', [
                'phone'  => $phone,
                'otp_id' => $otpRecord->id,
            ]);

            return [
                'success' => false,
                'reason'  => 'expired',
                'message' => 'انتهت صلاحية الرمز، يرجى طلب رمز جديد',
            ];
        }

        // 3. التحقق من عدد المحاولات
        if (!$otpRecord->canAttempt()) {
            $otpRecord->delete();

            Log::warning('OTP max attempts exceeded', [
                'phone'    => $phone,
                'otp_id'   => $otpRecord->id,
                'attempts' => $otpRecord->attempts,
            ]);

            return [
                'success' => false,
                'reason'  => 'max_attempts',
                'message' => 'تم تجاوز عدد المحاولات المسموح بها، يرجى طلب رمز جديد',
            ];
        }

        // 4. التحقق من الرمز
        $isValid = $otpRecord->verifyCode($otp);

        if (!$isValid) {
            Log::warning('OTP invalid', [
                'phone'      => $phone,
                'otp_id'     => $otpRecord->id,
                'attempts'   => $otpRecord->attempts,
                'remaining'  => $otpRecord->remaining_attempts,
            ]);

            return [
                'success'            => false,
                'reason'             => 'invalid',
                'message'            => 'رمز التحقق غير صحيح',
                'remaining_attempts' => $otpRecord->remaining_attempts,
            ];
        }

        // 5. نجاح
        Log::info('OTP verified successfully', [
            'phone'   => $phone,
            'otp_id'  => $otpRecord->id,
            'ip'      => request()->ip(),
        ]);

        return [
            'success' => true,
            'message' => 'تم التحقق بنجاح',
            'otp_id'  => $otpRecord->id,
        ];
    }

    /**
     * إعادة تعيين كلمة المرور
     */
    public function resetPassword(string $phone, string $newPassword): bool
    {
        $user = User::where('phone', $phone)->first();

        if (!$user) {
            return false;
        }

        DB::beginTransaction();

        try {
            $user->update([
                'password' => bcrypt($newPassword),
            ]);

            // حذف جميع OTPs لهذا الرقم
            OTPVerification::where('phone', $phone)->delete();

            // حذف جميع التوكنات
            $user->tokens()->delete();

            DB::commit();

            Log::info('Password reset successfully', [
                'user_id' => $user->id,
                'phone'   => $phone,
                'ip'      => request()->ip(),
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Password reset failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}