<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Address;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class AddressController extends Controller
{
    /**
     * إنشاء عنوان لمستخدم Owner
     */
    public function storeAddressOwner(Request $request)
    {
        try {
            // 1. التحقق من أن المستخدم مسجل دخول
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                    'error_type' => 'unauthenticated',
                    'status_code' => 401
                ], 401);
            }

            // 2. التحقق من أن المستخدم هو Owner (role = 2)
            $userRole = Role::where('user_id', $user->id)->first();
            
            if (!$userRole || $userRole->role != 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط أصحاب المواقف يمكنهم إضافة عنوان',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 3. التحقق من صحة البيانات
            $request->validate([
                'city' => 'required|string|max:100',
                'street' => 'required|string|max:191',
                'neighborhood' => 'required|string|max:100',
            ], [
                'city.required' => 'المدينة مطلوبة',
                'city.string' => 'المدينة يجب أن تكون نصاً',
                'city.max' => 'المدينة لا تتجاوز 100 حرف',
                
                'street.required' => 'الشارع مطلوب',
                'street.string' => 'الشارع يجب أن يكون نصاً',
                'street.max' => 'الشارع لا يتجاوز 191 حرف',
                
                'neighborhood.required' => 'الحي مطلوب',
                'neighborhood.string' => 'الحي يجب أن يكون نصاً',
                'neighborhood.max' => 'الحي لا يتجاوز 100 حرف',
            ]);

            // 4. التحقق من وجود عنوان سابق للمستخدم
            $existingAddress = Address::where('user_id', $user->id)->first();
            
            if ($existingAddress) {
                return response()->json([
                    'success' => false,
                    'message' => 'لديك عنوان مسجل بالفعل، يمكنك تحديثه بدلاً من إضافة جديد',
                    'error_type' => 'address_exists',
                    'data' => $existingAddress,
                    'status_code' => 400
                ], 400);
            }

            // 5. إنشاء العنوان
            $address = Address::create([
                'user_id' => $user->id,
                'city' => $request->city,
                'street' => $request->street,
                'neighborhood' => $request->neighborhood,
            ]);

            // 6. تسجيل العملية
            Log::info('تم إنشاء عنوان لصاحب الموقف', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'address_id' => $address->id,
                'city' => $request->city,
                'street' => $request->street,
                'neighborhood' => $request->neighborhood,
                'ip' => $request->ip()
            ]);

            // 7. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء العنوان بنجاح',
                'data' => [
                    'address' => $address,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                    ]
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل التحقق من البيانات',
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422
            ], 422);

        } catch (QueryException $e) {
            Log::error('خطأ في قاعدة البيانات أثناء إنشاء العنوان', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في قاعدة البيانات',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'database_error',
                'status_code' => 500
            ], 500);

        } catch (\Exception $e) {
            Log::error('خطأ في إنشاء العنوان', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إنشاء العنوان',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }






    /**
     * عرض عنوان المستخدم الحالي
     */

    public function getAddress(Request $request)
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

            $address = Address::where('user_id', $user->id)->first();

            if (!$address) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد عنوان مسجل',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب العنوان بنجاح',
                'data' => $address
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * تحديث عنوان المستخدم الحالي
     */
    public function updateAddress(Request $request)
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

            $address = Address::where('user_id', $user->id)->first();

            if (!$address) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد عنوان مسجل للتحديث',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            $request->validate([
                'city' => 'sometimes|string|max:100',
                'street' => 'sometimes|string|max:191',
                'neighborhood' => 'sometimes|string|max:100',
            ]);

            // التحقق من وجود بيانات للتحديث
            if (!$request->has('city') && !$request->has('street') && !$request->has('neighborhood')) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب إدخال مدينة أو شارع أو حي للتحديث',
                    'error_type' => 'no_data_provided',
                    'status_code' => 400
                ], 400);
            }

            $oldData = [
                'city' => $address->city,
                'street' => $address->street,
                'neighborhood' => $address->neighborhood,
            ];

            // تحديث البيانات
            if ($request->has('city')) {
                $address->city = $request->city;
            }
            if ($request->has('street')) {
                $address->street = $request->street;
            }
            if ($request->has('neighborhood')) {
                $address->neighborhood = $request->neighborhood;
            }
            
            $address->save();

            Log::info('تم تحديث العنوان', [
                'user_id' => $user->id,
                'address_id' => $address->id,
                'old_data' => $oldData,
                'new_data' => [
                    'city' => $address->city,
                    'street' => $address->street,
                    'neighborhood' => $address->neighborhood,
                ],
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث العنوان بنجاح',
                'data' => $address,
                'old_data' => $oldData
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'error_type' => 'validation_error'
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * حذف عنوان المستخدم الحالي
     */
    public function deleteAddress(Request $request)
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

            $address = Address::where('user_id', $user->id)->first();

            if (!$address) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد عنوان مسجل للحذف',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            $address->delete();

            Log::info('تم حذف العنوان', [
                'user_id' => $user->id,
                'address_id' => $address->id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف العنوان بنجاح'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}