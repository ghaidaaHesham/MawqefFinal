<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PaymentAmount;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class PaymentAmountController extends Controller
{
   
    /**
     * عرض  مبالغ الدفع (للمدير وصاحب الموقف)
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك',
                    'error_type' => 'unauthenticated',
                    'status_code' => 401
                ], 401);
            }

            $userRole = Role::where('user_id', $user->id)->first();
            
            if (!$userRole) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد دور للمستخدم',
                    'error_type' => 'role_not_found',
                    'status_code' => 404
                ], 404);
            }

            $isAdmin = $userRole->role == 1;
            $isOwner = $userRole->role == 2;

            if (!$isAdmin && !$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط المدير أو صاحب الموقف يمكنه عرض مبالغ الدفع',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            $paymentAmounts = PaymentAmount::orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مبالغ الدفع بنجاح',
                'data' => $paymentAmounts,
                'count' => $paymentAmounts->count()
            ], 200);

        } catch (\Exception $e) {
            Log::error('خطأ في عرض مبالغ الدفع', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء عرض مبالغ الدفع',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }

    
    /**
     * تحديث مبلغ الدفع (للمدير فقط)
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك',
                    'error_type' => 'unauthenticated',
                    'status_code' => 401
                ], 401);
            }

            $userRole = Role::where('user_id', $user->id)->first();
            
            if (!$userRole || $userRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط المدير يمكنه تحديث مبالغ الدفع',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            $paymentAmount = PaymentAmount::find($id);

            if (!$paymentAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ الدفع غير موجود',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            $request->validate([
                'payment_amount' => 'sometimes|numeric|min:0',
                'booking_number' => 'sometimes|string|max:191',
            ]);

            $oldData = [
                'payment_amount' => $paymentAmount->payment_amount,
                'booking_number' => $paymentAmount->booking_number,
            ];

            if ($request->has('payment_amount')) {
                $paymentAmount->payment_amount = $request->payment_amount;
            }

            if ($request->has('booking_number')) {
                $paymentAmount->booking_number = $request->booking_number;
            }

            $paymentAmount->save();

            Log::info('تم تحديث مبلغ الدفع', [
                'admin_id' => $user->id,
                'payment_amount_id' => $paymentAmount->id,
                'old_data' => $oldData,
                'new_data' => [
                    'payment_amount' => $paymentAmount->payment_amount,
                    'booking_number' => $paymentAmount->booking_number,
                ],
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث مبلغ الدفع بنجاح',
                'data' => [
                    'payment_amount' => $paymentAmount,
                    'old_data' => $oldData,
                ]
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422
            ], 422);

        } catch (\Exception $e) {
            Log::error('خطأ في تحديث مبلغ الدفع', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديث مبلغ الدفع',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }

    /**
     * حذف مبلغ الدفع (للمدير فقط)
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك',
                    'error_type' => 'unauthenticated',
                    'status_code' => 401
                ], 401);
            }

            $userRole = Role::where('user_id', $user->id)->first();
            
            if (!$userRole || $userRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط المدير يمكنه حذف مبالغ الدفع',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            $paymentAmount = PaymentAmount::find($id);

            if (!$paymentAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ الدفع غير موجود',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            $paymentAmount->delete();

            Log::info('تم حذف مبلغ الدفع', [
                'admin_id' => $user->id,
                'payment_amount_id' => $paymentAmount->id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف مبلغ الدفع بنجاح'
            ], 200);

        } catch (\Exception $e) {
            Log::error('خطأ في حذف مبلغ الدفع', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف مبلغ الدفع',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }
}