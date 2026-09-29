<?php

namespace App\Http\Controllers;

use App\Http\Requests\RequestOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\OTPVerification;
use App\Models\PhonePasswordReset;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\OtpService;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class OtpController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OtpService $otpService
    ) {}

    /**
     * طلب إرسال OTP
     */
    public function request(RequestOtpRequest $request)
    {
        try {
            $result = $this->otpService->request(
                $request->phone,
                OTPVerification::PURPOSE_PASSWORD_RESET
            );

            if (!$result['allowed']) {
                return $this->errorResponse(
                    $result['message'],
                    $result['reason'],
                    429,
                    array_filter([
                        'wait_seconds' => $result['wait_seconds'] ?? null,
                        'daily_count'  => $result['daily_count'] ?? null,
                        'max_per_day'  => $result['max_per_day'] ?? null,
                    ])
                );
            }

            return $this->successResponse(
                [
                    'phone'      => $request->phone,
                    'expires_in' => $result['expires_in'],
                    'expires_at' => $result['expires_at']->toDateTimeString(),
                ],
                'تم إرسال رمز التحقق بنجاح'
            );

        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());

        } catch (QueryException $e) {
            Log::error('DB error in OTP request', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);
            return $this->errorResponse(
                'حدث خطأ في قاعدة البيانات',
                'database_error',
                500
            );

        } catch (\Exception $e) {
            Log::error('Error in OTP request', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);
            return $this->errorResponse(
                'حدث خطأ أثناء إرسال رمز التحقق',
                'general_error',
                500
            );
        }
    }

    /**
 * التحقق من OTP
 */
public function verify(VerifyOtpRequest $request)
{
    try {
        $result = $this->otpService->verify(
            $request->phone,
            $request->otp,
            OTPVerification::PURPOSE_PASSWORD_RESET
        );

        if (!$result['success']) {
            return $this->errorResponse(
                $result['message'],
                $result['reason'],
                400,
                isset($result['remaining_attempts'])
                    ? ['remaining_attempts' => $result['remaining_attempts']]
                    : null
            );
        }

        // ✅ البحث عن المستخدم
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return $this->errorResponse(
                'المستخدم غير موجود',
                'user_not_found',
                404
            );
        }

        // ✅ إنشاء reset_token مؤقت
        $resetToken = PhonePasswordReset::createFor($user->id);

        // ✅ تسجيل العملية
        Log::info('تم التحقق من OTP وإنشاء reset_token', [
            'user_id'    => $user->id,
            'phone'      => $user->phone,
            'token_id'   => $resetToken->id,
            'expires_at' => $resetToken->expires_at,
            'ip'         => $request->ip(),
        ]);

        // ✅ إرجاع الاستجابة مع reset_token
        return $this->successResponse(
            [
                'phone'       => $request->phone,
                'reset_token' => $resetToken->token,
                'expires_in'  => PhonePasswordReset::TOKEN_EXPIRY_MINUTES,
                'expires_at'  => $resetToken->expires_at->toDateTimeString(),
                'verified_at' => now()->toDateTimeString(),
            ],
            'تم التحقق من الرمز بنجاح'
        );

    } catch (ValidationException $e) {
        return $this->validationErrorResponse($e->errors());

    } catch (\Exception $e) {
        Log::error('Error in OTP verify', [
            'error' => $e->getMessage(),
            'phone' => $request->phone ?? null,
        ]);
        return $this->errorResponse(
            'حدث خطأ أثناء التحقق من الرمز',
            'general_error',
            500
        );
    }
}


















/**
 * إعادة تعيين كلمة المرور باستخدام reset_token
 */
public function resetPassword(Request $request)
{
    try {
        // ============================================
        // 1. التحقق من صحة البيانات
        // ============================================
        $request->validate([
            'reset_token'               => 'required|string|size:64',
            'new_password'              => 'required|string|min:8|confirmed',
            'new_password_confirmation' => 'required|string|min:8',
        ], [
            'reset_token.required' => 'رمز إعادة التعيين مطلوب',
            'reset_token.string'   => 'رمز إعادة التعيين غير صحيح',
            'reset_token.size'     => 'رمز إعادة التعيين غير صحيح',

            'new_password.required'  => 'كلمة المرور الجديدة مطلوبة',
            'new_password.string'    => 'كلمة المرور الجديدة يجب أن تكون نصاً',
            'new_password.min'       => 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل',
            'new_password.confirmed' => 'تأكيد كلمة المرور غير متطابق',

            'new_password_confirmation.required' => 'تأكيد كلمة المرور مطلوب',
            'new_password_confirmation.string'   => 'تأكيد كلمة المرور يجب أن يكون نصاً',
            'new_password_confirmation.min'      => 'تأكيد كلمة المرور يجب أن يكون 8 أحرف على الأقل',
        ]);

        // ============================================
        // 2. البحث عن reset_token صالح
        // ============================================
        $resetToken = PhonePasswordReset::findValid($request->reset_token);

        if (!$resetToken) {
            return $this->errorResponse(
                'رمز إعادة التعيين غير صالح أو منتهي الصلاحية',
                'invalid_reset_token',
                400
            );
        }

        // ============================================
        // 3. البحث عن المستخدم
        // ============================================
        $user = User::find($resetToken->user_id);

        if (!$user) {
            return $this->errorResponse(
                'المستخدم غير موجود',
                'user_not_found',
                404
            );
        }

        // ============================================
        // 4. التحقق من أن كلمة المرور الجديدة مختلفة
        // ============================================
        if (Hash::check($request->new_password, $user->password)) {
            return $this->errorResponse(
                'كلمة المرور الجديدة يجب أن تكون مختلفة عن الحالية',
                'same_password',
                400
            );
        }

        // ============================================
        // 5. Transaction
        // ============================================
        DB::beginTransaction();

        try {
            // 5.1 تحديث كلمة المرور
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            // 5.2 تعليم reset_token كمستخدم
            $resetToken->markAsUsed();

            // 5.3 حذف جميع OTPs لهذا الرقم
            OTPVerification::where('phone', $user->phone)->delete();

            // 5.4 حذف جميع التوكنات (تسجيل خروج من جميع الأجهزة)
            $user->tokens()->delete();

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // ============================================
        // 6. تسجيل العملية
        // ============================================
        Log::info('تم إعادة تعيين كلمة المرور', [
            'user_id'  => $user->id,
            'phone'    => $user->phone,
            'reset_at' => now()->toDateTimeString(),
            'ip'       => $request->ip(),
        ]);

        // ============================================
        // 7. إرجاع الاستجابة
        // ============================================
        return $this->successResponse(
            [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'phone' => $user->phone,
                ],
                'reset_at' => now()->toDateTimeString(),
            ],
            'تم إعادة تعيين كلمة المرور بنجاح'
        );

    } catch (ValidationException $e) {
        return $this->validationErrorResponse($e->errors());

    } catch (QueryException $e) {
        Log::error('DB error in password reset', ['error' => $e->getMessage()]);

        return $this->errorResponse(
            'حدث خطأ في قاعدة البيانات',
            'database_error',
            500
        );

    } catch (\Exception $e) {
        Log::error('Error in password reset', ['error' => $e->getMessage()]);

        return $this->errorResponse(
            'حدث خطأ أثناء إعادة تعيين كلمة المرور',
            'general_error',
            500
        );
    }
}










/**
 * تغيير كلمة المرور للمستخدم المسجل
 * يطلب: current_password, new_password, new_password_confirmation
 * كل مستخدم يغيّر كلمته فقط (بناءً على التوكن)
 */
public function changePassword(Request $request)
{
    try {
        // ============================================
        // 1. التحقق من أن المستخدم مسجل دخول
        // ============================================
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success'     => false,
                'message'     => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                'error_type'  => 'unauthenticated',
                'status_code' => 401,
            ], 401);
        }

        // ============================================
        // 2. التحقق من صحة البيانات
        // ============================================
        $request->validate([
            'current_password'          => 'required|string|min:8',
            'new_password'              => 'required|string|min:8|confirmed|different:current_password',
            'new_password_confirmation' => 'required|string|min:8',
        ], [
            'current_password.required' => 'كلمة المرور الحالية مطلوبة',
            'current_password.string'   => 'كلمة المرور الحالية يجب أن تكون نصاً',
            'current_password.min'      => 'كلمة المرور الحالية يجب أن تكون 8 أحرف على الأقل',
            
            'new_password.required'     => 'كلمة المرور الجديدة مطلوبة',
            'new_password.string'       => 'كلمة المرور الجديدة يجب أن تكون نصاً',
            'new_password.min'          => 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل',
            'new_password.confirmed'    => 'تأكيد كلمة المرور غير متطابق',
            'new_password.different'    => 'كلمة المرور الجديدة يجب أن تكون مختلفة عن الحالية',
            
            'new_password_confirmation.required' => 'تأكيد كلمة المرور مطلوب',
            'new_password_confirmation.string'   => 'تأكيد كلمة المرور يجب أن يكون نصاً',
            'new_password_confirmation.min'      => 'تأكيد كلمة المرور يجب أن يكون 8 أحرف على الأقل',
        ]);

        // ============================================
        // 3. التحقق من كلمة المرور الحالية
        // ============================================
        if (!Hash::check($request->current_password, $user->password)) {
            Log::warning('محاولة تغيير كلمة مرور فاشلة - كلمة المرور الحالية خاطئة', [
                'user_id' => $user->id,
                'phone'   => $user->phone,
                'ip'      => $request->ip(),
            ]);

            return response()->json([
                'success'     => false,
                'message'     => 'كلمة المرور الحالية غير صحيحة',
                'error_type'  => 'invalid_current_password',
                'status_code' => 401,
            ], 401);
        }

        // ============================================
        // 4. التحقق من أن كلمة المرور الجديدة مختلفة
        // ============================================
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'success'     => false,
                'message'     => 'كلمة المرور الجديدة يجب أن تكون مختلفة عن الحالية',
                'error_type'  => 'same_password',
                'status_code' => 400,
            ], 400);
        }

        // ============================================
        // 5. Transaction
        // ============================================
        DB::beginTransaction();

        try {
            // 5.1 تحديث كلمة المرور
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            // 5.2 حذف جميع التوكنات (تسجيل خروج من جميع الأجهزة)
            // ⚠️ هذا اختياري - يمكنك حذفه إذا أردت إبقاء المستخدم مسجلاً
            $user->tokens()->delete();

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // ============================================
        // 6. تسجيل العملية
        // ============================================
        Log::info('تم تغيير كلمة المرور', [
            'user_id'    => $user->id,
            'phone'      => $user->phone,
            'ip'         => $request->ip(),
            'changed_at' => now()->toDateTimeString(),
        ]);

        // ============================================
        // 7. إرجاع الاستجابة
        // ============================================
        return response()->json([
            'success' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح',
            'data'    => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'phone' => $user->phone,
                ],
                'changed_at'    => now()->toDateTimeString(),
                'tokens_revoked' => true,
                'note'          => 'تم تسجيل الخروج من جميع الأجهزة، يرجى تسجيل الدخول مرة أخرى',
            ],
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'success'     => false,
            'message'     => 'فشل التحقق من البيانات',
            'errors'      => $e->errors(),
            'error_type'  => 'validation_error',
            'status_code' => 422,
        ], 422);

    } catch (QueryException $e) {
        Log::error('DB error in change password', ['error' => $e->getMessage()]);

        return response()->json([
            'success'     => false,
            'message'     => 'حدث خطأ في قاعدة البيانات',
            'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type'  => 'database_error',
            'status_code' => 500,
        ], 500);

    } catch (\Exception $e) {
        Log::error('Error in change password', ['error' => $e->getMessage()]);

        return response()->json([
            'success'     => false,
            'message'     => 'حدث خطأ أثناء تغيير كلمة المرور',
            'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type'  => 'general_error',
            'status_code' => 500,
        ], 500);
    }
}
}