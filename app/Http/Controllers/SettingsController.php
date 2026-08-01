<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\imageUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use App\Models\User;
use App\Models\PasswordReset;
use Carbon\Carbon;

class SettingsController extends Controller
{
    //

//update language

    public function updateLanguage(Request $request)
{
    try {
        // المستخدم موجود بالفعل (auth:sanctum)
        $user = $request->user();

        // التحقق من اللغة
        $request->validate([
            'language' => 'required|in:en,ar',
        ]);

        // التحقق من أن اللغة مختلفة
        if ($user->language === $request->language) {
            return response()->json([
                'success' => false,
                'message' => 'نفس اللغة الحالية',
            ], 400);
        }

        // تحديث اللغة
        $user->update([
            'language' => $request->language
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تغيير اللغة بنجاح',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'language' => $user->language,
                ]
            ]
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'errors' => $e->errors()
        ], 422);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ',
            'error' => $e->getMessage()
        ], 500);
    }
}

//updateProfile


    public function updateProfile(Request $request)
    {
        try {
            // 1. جلب المستخدم الحالي
            $user = $request->user();

            // 2. التحقق من وجود بيانات للتحديث
            if (!$request->has('name') && !$request->has('phone')) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب إدخال اسم أو رقم هاتف للتحديث',
                    'error_type' => 'no_data_provided',
                    'status_code' => 400
                ], 400);
            }

            // 3. بناء قواعد التحقق حسب البيانات المرسلة
            $rules = [];
            $messages = [];

            if ($request->has('name')) {
                $rules['name'] = 'required|string|max:191';
                $messages['name.required'] = 'الاسم مطلوب';
                $messages['name.string'] = 'الاسم يجب أن يكون نصاً';
                $messages['name.max'] = 'الاسم لا يتجاوز 191 حرف';
            }

            if ($request->has('phone')) {
                $rules['phone'] = 'required|string|max:191|regex:/^09[0-9]{8}$/|unique:users,phone,' . $user->id;
                $messages['phone.required'] = 'رقم الهاتف مطلوب';
                $messages['phone.string'] = 'رقم الهاتف يجب أن يكون نصاً';
                $messages['phone.max'] = 'رقم الهاتف لا يتجاوز 191 حرف';
                $messages['phone.regex'] = 'رقم الهاتف يجب أن يتألف من 10 أرقام ويبدأ بـ 09';
                $messages['phone.unique'] = 'رقم الهاتف مستخدم بالفعل';
            }

            // 4. تنفيذ التحقق
            $request->validate($rules, $messages);

            // 5. تخزين البيانات القديمة للتسجيل
            $oldData = [
                'name' => $user->name,
                'phone' => $user->phone,
            ];

            // 6. تحديث البيانات
            $updatedFields = [];

            if ($request->has('name')) {
                // التحقق من أن الاسم مختلف
                if ($user->name === $request->name) {
                    return response()->json([
                        'success' => false,
                        'message' => 'الاسم الجديد هو نفس الاسم الحالي',
                        'error_type' => 'same_name',
                        'status_code' => 400,
                        'current_name' => $user->name
                    ], 400);
                }
                
                $user->name = $request->name;
                $updatedFields[] = 'name';
            }

            if ($request->has('phone')) {
                // التحقق من أن رقم الهاتف مختلف
                if ($user->phone === $request->phone) {
                    return response()->json([
                        'success' => false,
                        'message' => 'رقم الهاتف الجديد هو نفس الرقم الحالي',
                        'error_type' => 'same_phone',
                        'status_code' => 400,
                        'current_phone' => $user->phone
                    ], 400);
                }
                
                $user->phone = $request->phone;
                $updatedFields[] = 'phone';
            }

            // 7. حفظ التغييرات
            $user->save();

            // 8. تسجيل عملية التحديث
            Log::info('تم تحديث الملف الشخصي', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'old_data' => $oldData,
                'updated_fields' => $updatedFields,
                'ip' => $request->ip()
            ]);

            // 9. جلب الدور الخاص بالمستخدم
            $role = Role::where('user_id', $user->id)->first();

            // 10. إرجاع الاستجابة الناجحة
            $message = count($updatedFields) === 2 
                ? 'تم تحديث الاسم ورقم الهاتف بنجاح'
                : (in_array('name', $updatedFields) 
                    ? 'تم تحديث الاسم بنجاح' 
                    : 'تم تحديث رقم الهاتف بنجاح');

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'language' => $user->language,
                    ],
                    'role' => $role,
                    'updated_fields' => $updatedFields,
                    'old_data' => $oldData,
                    'updated_at' => now()->toDateTimeString()
                ]
            ], 200);

        } catch (ValidationException $e) {
            // معالجة أخطاء التحقق
            return response()->json([
                'success' => false,
                'message' => 'فشل التحقق من البيانات',
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422
            ], 422);

        } catch (QueryException $e) {
            // معالجة أخطاء قاعدة البيانات
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في قاعدة البيانات',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'database_error',
                'status_code' => 500
            ], 500);

        } catch (\Exception $e) {
            // معالجة أي أخطاء أخرى
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ غير متوقع أثناء تحديث الملف الشخصي',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500,
                'trace' => config('app.debug') ? $e->getTrace() : null
            ], 500);
        }
    }



//changePassword

   
    public function changePassword(Request $request)
    {
        try {
            // 1. جلب المستخدم الحالي
            $user = $request->user();

            // 2. التحقق من صحة البيانات
            $request->validate([
                'current_password' => 'required|string|min:8',
                'new_password' => 'required|string|min:8|confirmed',
                'new_password_confirmation' => 'required|string|min:8',
            ], [
                'current_password.required' => 'كلمة المرور الحالية مطلوبة',
                'current_password.string' => 'كلمة المرور الحالية يجب أن تكون نصاً',
                'current_password.min' => 'كلمة المرور الحالية يجب أن تتجاوز 8 أحرف',
                
                'new_password.required' => 'كلمة المرور الجديدة مطلوبة',
                'new_password.string' => 'كلمة المرور الجديدة يجب أن تكون نصاً',
                'new_password.min' => 'كلمة المرور الجديدة يجب أن تتجاوز 8 أحرف',
                'new_password.confirmed' => 'تأكيد كلمة المرور غير متطابق',
                
                'new_password_confirmation.required' => 'تأكيد كلمة المرور مطلوب',
                'new_password_confirmation.string' => 'تأكيد كلمة المرور يجب أن يكون نصاً',
                'new_password_confirmation.min' => 'تأكيد كلمة المرور يجب أن تتجاوز 8 أحرف',
            ]);

            // 3. التحقق من أن كلمة المرور الحالية صحيحة
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'كلمة المرور الحالية غير صحيحة',
                    'error_type' => 'invalid_current_password',
                    'status_code' => 401
                ], 401);
            }

            // 4. التحقق من أن كلمة المرور الجديدة مختلفة عن الحالية
            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'كلمة المرور الجديدة يجب أن تكون مختلفة عن الحالية',
                    'error_type' => 'same_password',
                    'status_code' => 400
                ], 400);
            }

            // 5. التحقق من قوة كلمة المرور (اختياري)
            $this->validatePasswordStrength($request->new_password);

            // 6. تسجيل محاولة تغيير كلمة المرور
            Log::info('محاولة تغيير كلمة المرور', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'ip' => $request->ip()
            ]);

            // 7. تحديث كلمة المرور
            $user->password = Hash::make($request->new_password);
            $user->save();

            // 8. تسجيل نجاح تغيير كلمة المرور
            Log::info('تم تغيير كلمة المرور بنجاح', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'ip' => $request->ip()
            ]);

            // 9. حذف جميع التوكنات (اختياري - لتسجيل الخروج من جميع الأجهزة)
            // $user->tokens()->delete();

            // 10. إرجاع الاستجابة الناجحة
            return response()->json([
                'success' => true,
                'message' => 'تم تغيير كلمة المرور بنجاح',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                    ],
                    'changed_at' => now()->toDateTimeString()
                ]
            ], 200);

        } catch (ValidationException $e) {
            // معالجة أخطاء التحقق
            return response()->json([
                'success' => false,
                'message' => 'فشل التحقق من البيانات',
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422
            ], 422);

        } catch (QueryException $e) {
            // معالجة أخطاء قاعدة البيانات
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في قاعدة البيانات',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'database_error',
                'status_code' => 500
            ], 500);

        } catch (\Exception $e) {
            // معالجة أي أخطاء أخرى
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ غير متوقع أثناء تغيير كلمة المرور',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500,
                'trace' => config('app.debug') ? $e->getTrace() : null
            ], 500);
        }
    }






    /**
 * التحقق من قوة كلمة المرور
 */
private function validatePasswordStrength($password)
{
    $errors = [];

    // التحقق من طول كلمة المرور
    if (strlen($password) < 8) {
        $errors[] = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
    }

    // التحقق من وجود حرف كبير
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'يجب أن تحتوي كلمة المرور على حرف كبير واحد على الأقل';
    }

    // التحقق من وجود حرف صغير
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'يجب أن تحتوي كلمة المرور على حرف صغير واحد على الأقل';
    }

    // التحقق من وجود رقم
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل';
    }

    // التحقق من وجود رمز خاص
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = 'يجب أن تحتوي كلمة المرور على رمز خاص واحد على الأقل';
    }

    if (!empty($errors)) {
        throw new \Exception(implode(' | ', $errors));
    }
}



        /**
     * تغيير الصورة الشخصية للمستخدم
     */
    public function updateProfileImage(Request $request)
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

            // 2. التحقق من صحة الصورة
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            ], [
                'image.required' => 'الصورة مطلوبة',
                'image.image' => 'يجب أن تكون الصورة بصيغة صورة',
                'image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
                'image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
            ]);

            // 3. حذف الصورة القديمة (إذا وجدت)
            $oldImage = imageUser::where('imageable_id', $user->id)
                ->where('imageable_type', 'App\Models\User')
                ->first();

            if ($oldImage) {
                // حذف الملف من التخزين
                if (Storage::disk('public')->exists($oldImage->path)) {
                    Storage::disk('public')->delete($oldImage->path);
                }
                // حذف السجل من قاعدة البيانات
                $oldImage->delete();
            }

            // 4. رفع الصورة الجديدة
            $image = $request->file('image');
            
            // إنشاء اسم فريد للصورة
            $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
            
            // حفظ الصورة في مجلد users
            $path = $image->storeAs('users', $imageName, 'public');
            
            // حفظ في جدول image_user
            $newImage = imageUser::create([
                'path' => $path,
                'title' => $user->name . '_profile_' . time(),
                'imageable_type' => 'App\Models\User',
                'imageable_id' => $user->id,
            ]);

            // 5. تسجيل العملية
            Log::info('تم تغيير الصورة الشخصية', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'image_id' => $newImage->id,
                'image_path' => $path,
                'ip' => $request->ip()
            ]);

            // 6. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => 'تم تغيير الصورة الشخصية بنجاح',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                    ],
                    'image' => $newImage,
                    'image_url' => asset('storage/' . $path),
                ]
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل التحقق من البيانات',
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422
            ], 422);

        } catch (QueryException $e) {
            Log::error('خطأ في قاعدة البيانات أثناء تغيير الصورة الشخصية', [
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
            Log::error('خطأ في تغيير الصورة الشخصية', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء تغيير الصورة الشخصية',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }

    /**
     * حذف الصورة الشخصية
     */
    public function deleteProfileImage(Request $request)
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

            // البحث عن الصورة
            $image = imageUser::where('imageable_id', $user->id)
                ->where('imageable_type', 'App\Models\User')
                ->first();

            if (!$image) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد صورة شخصية لحذفها',
                    'error_type' => 'image_not_found',
                    'status_code' => 404
                ], 404);
            }

            // حذف الملف من التخزين
            if (Storage::disk('public')->exists($image->path)) {
                Storage::disk('public')->delete($image->path);
            }

            // حذف السجل من قاعدة البيانات
            $image->delete();

            Log::info('تم حذف الصورة الشخصية', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الصورة الشخصية بنجاح'
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























