<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Sms\TraccarSmsService;
use App\Models\OTPVerification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OTPController extends Controller
{
    protected TraccarSmsService $smsService;

    public function __construct(TraccarSmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * إرسال OTP
     */
    public function sendOtp(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string|regex:/^09[0-9]{8}$/|exists:users,phone',
            ]);

            $phone = $request->phone;

            // التحقق من طلبات متكررة
            $recentOTP = OTPVerification::where('phone', $phone)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->where('is_used', false)
                ->first();

            if ($recentOTP) {
                return response()->json([
                    'success' => false,
                    'message' => 'تم إرسال رمز التحقق بالفعل، يرجى الانتظار',
                    'error_type' => 'otp_already_sent',
                    'status_code' => 429,
                    'remaining_time' => now()->diffInSeconds($recentOTP->created_at->addSeconds(30)),
                ], 429);
            }

            // إنشاء OTP
            $otpRecord = OTPVerification::createOTP($phone);
            $otp = $otpRecord->otp;

            // إرسال الرسالة
            $message = "🔐 رمز التحقق الخاص بك هو: {$otp}\n\n⏱ هذا الرمز صالح لمدة 5 دقائق فقط.";

            try {
                $this->smsService->send($phone, $message);
            } catch (\Exception $e) {
                $otpRecord->delete();
                throw $e;
            }

            Log::info('✅ OTP sent successfully', [
                'phone' => $phone,
                'otp' => $otp,
            ]);

            return response()->json([
                'success' => true,
                'message' => '✅ تم إرسال رمز التحقق إلى رقم هاتفك',
                'data' => [
                    'phone' => $phone,
                    'expires_in' => 5,
                    'expires_at' => $otpRecord->expires_at->toDateTimeString(),
                ],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422,
            ], 422);
        } catch (\Exception $e) {
            Log::error('❌ Error sending OTP', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إرسال رمز التحقق: ' . $e->getMessage(),
                'error_type' => 'general_error',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * إعادة إرسال OTP
     */
    public function resendOtp(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string|regex:/^09[0-9]{8}$/|exists:users,phone',
            ]);

            $phone = $request->phone;

            // حذف OTP القديم
            OTPVerification::where('phone', $phone)->where('is_used', false)->delete();

            // إنشاء OTP جديد
            $otpRecord = OTPVerification::createOTP($phone);
            $otp = $otpRecord->otp;

            // إرسال الرسالة
            $message = "🔐 رمز التحقق الخاص بك هو: {$otp}\n\n⏱ هذا الرمز صالح لمدة 5 دقائق فقط.";

            try {
                $this->smsService->send($phone, $message);
            } catch (\Exception $e) {
                $otpRecord->delete();
                throw $e;
            }

            Log::info('🔄 OTP resent successfully', [
                'phone' => $phone,
                'otp' => $otp,
            ]);

            return response()->json([
                'success' => true,
                'message' => '✅ تم إعادة إرسال رمز التحقق',
                'data' => [
                    'phone' => $phone,
                    'expires_in' => 5,
                    'expires_at' => $otpRecord->expires_at->toDateTimeString(),
                ],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422,
            ], 422);
        } catch (\Exception $e) {
            Log::error('❌ Error resending OTP', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إعادة إرسال رمز التحقق: ' . $e->getMessage(),
                'error_type' => 'general_error',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * التحقق من OTP
     */
    public function verifyOtp(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string|regex:/^09[0-9]{8}$/',
                'otp' => 'required|string|size:6',
            ]);

            $phone = $request->phone;
            $otp = $request->otp;

            $otpRecord = OTPVerification::where('phone', $phone)
                ->where('otp', $otp)
                ->where('is_used', false)
                ->first();

            if (!$otpRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'رمز التحقق غير صحيح',
                    'error_type' => 'invalid_otp',
                    'status_code' => 400,
                ], 400);
            }

            if ($otpRecord->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'رمز التحقق منتهي الصلاحية',
                    'error_type' => 'otp_expired',
                    'status_code' => 400,
                ], 400);
            }

            if (!$otpRecord->canAttempt()) {
                return response()->json([
                    'success' => false,
                    'message' => 'تم تجاوز عدد المحاولات المسموح بها',
                    'error_type' => 'max_attempts_exceeded',
                    'status_code' => 400,
                ], 400);
            }

            $otpRecord->incrementAttempts();

            if ($otpRecord->otp === $otp) {
                $otpRecord->is_used = true;
                $otpRecord->save();

                $user = User::where('phone', $phone)->first();
                $resetToken = $user->createToken('password-reset-token')->plainTextToken;

                return response()->json([
                    'success' => true,
                    'message' => '✅ تم التحقق من الرمز بنجاح',
                    'data' => [
                        'phone' => $phone,
                        'reset_token' => $resetToken,
                        'verified_at' => now()->toDateTimeString(),
                    ],
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'رمز التحقق غير صحيح',
                'error_type' => 'invalid_otp',
                'status_code' => 400,
            ], 400);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422,
            ], 422);
        } catch (\Exception $e) {
            Log::error('❌ Error verifying OTP', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء التحقق من الرمز',
                'error_type' => 'general_error',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * إعادة تعيين كلمة المرور
     */
    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string|regex:/^09[0-9]{8}$/',
                'reset_token' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'المستخدم غير موجود',
                    'error_type' => 'user_not_found',
                    'status_code' => 404,
                ], 404);
            }

            $otpRecord = OTPVerification::where('phone', $request->phone)
                ->where('is_used', true)
                ->latest()
                ->first();

            if (!$otpRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'لم يتم التحقق من الرمز',
                    'error_type' => 'otp_not_verified',
                    'status_code' => 400,
                ], 400);
            }

            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'كلمة المرور الجديدة يجب أن تكون مختلفة',
                    'error_type' => 'same_password',
                    'status_code' => 400,
                ], 400);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            OTPVerification::where('phone', $request->phone)->delete();
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => '✅ تم إعادة تعيين كلمة المرور بنجاح',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                    ],
                    'reset_at' => now()->toDateTimeString(),
                ],
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422,
            ], 422);
        } catch (\Exception $e) {
            Log::error('❌ Error resetting password', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إعادة تعيين كلمة المرور',
                'error_type' => 'general_error',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * اختبار الاتصال بـ Traccar
     */
    public function testTraccar()
    {
        $result = $this->smsService->testConnection();

        return response()->json([
            'success' => $result['success'],
            'connection' => $result,
            'message' => $result['success'] ? '✅ Traccar is running!' : '❌ Traccar is not running',
            'tip' => 'تأكد من فتح تطبيق Traccar على الهاتف وتشغيل الخادم',
        ]);
    }
}