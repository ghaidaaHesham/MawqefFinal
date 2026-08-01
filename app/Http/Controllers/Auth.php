<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\imageUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Database\QueryException;
use Illuminate\Auth\AuthenticationException;
use RuntimeException;
use ErrorException;
class Auth extends Controller
{


//register

/**
 * تسجيل مستخدم جديد (مستأجر) مع إمكانية رفع صورة شخصية اختيارية
 */
/**
 * تسجيل مستخدم جديد (مستأجر) مع إمكانية رفع صورة شخصية اختيارية
 */
public function registerUserRenter(Request $request)
{
    try {
        // 1. التحقق من صحة البيانات
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'phone' => 'required|string|max:191|unique:users,phone|regex:/^09[0-9]{8}$/',
            'password' => 'required|string|min:8',
            'language' => 'sometimes|string|in:en,ar',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], [
            'name.required' => 'الاسم مطلوب',
            'name.string' => 'الاسم يجب أن يكون نصاً',
            'name.max' => 'الاسم لا يتجاوز 191 حرف',
            
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
            'phone.max' => 'رقم الهاتف لا يتجاوز 191 حرف',
            'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
            'phone.regex' => 'رقم الهاتف يجب أن يتألف من 10 أرقام ويبدأ بـ 09',
            
            'password.required' => 'كلمة المرور مطلوبة',
            'password.string' => 'كلمة المرور يجب أن تكون نصاً',
            'password.min' => 'كلمة المرور يجب أن تتجاوز 8 أحرف',
            
            'language.string' => 'اللغة يجب أن تكون نصاً',
            'language.in' => 'اللغة يجب أن تكون إما en أو ar',
            
            'image.image' => 'يجب أن تكون الصورة بصيغة صورة',
            'image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
            'image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
        ]);

        // 2. إنشاء المستخدم
        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'language' => $data['language'] ?? 'en',
        ]);

        // 3. رفع الصورة وحفظها (إذا وجدت)
        $uploadedImage = null;
        
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            
            if ($image && $image->isValid()) {
                // إنشاء اسم فريد للصورة
                $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                
                // حفظ الصورة في مجلد users
                $path = $image->storeAs('users', $imageName, 'public');
                
                // حفظ في جدول image_user باستخدام العلاقة المورف
                $uploadedImage = imageUser::create([
                    'path' => $path,
                    'title' => $user->name . '_profile_' . time(),
                    'imageable_type' => 'App\Models\User',
                    'imageable_id' => $user->id,
                ]);
            }
        }

        // 4. إنشاء دور للمستخدم (role = 3)
        $role = Role::create([
            'role' => 3,
            'user_id' => $user->id,
        ]);

        // 5. إنشاء التوكن
        $token = $user->createToken('ghaidaaProjectToken')->plainTextToken;

        // 6. تحميل العلاقة
        $user->load('role');

        // 7. تجهيز بيانات المستخدم للاستجابة
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'language' => $user->language,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'role' => $role,
            'image' => $uploadedImage,
        ];

        // 8. تجهيز الاستجابة
        $response = [
            'user' => $userData,
            'token' => $token,
        ];

        // 9. تسجيل العملية
        Log::info('تم تسجيل مستخدم جديد (مستأجر)', [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'has_image' => $uploadedImage ? true : false,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الحساب بنجاح',
            'data' => $response
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
        Log::error('خطأ في قاعدة البيانات أثناء تسجيل المستخدم', [
            'error' => $e->getMessage(),
            'phone' => $request->phone ?? null
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ في قاعدة البيانات',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'database_error',
            'status_code' => 500
        ], 500);
        
    } catch (\Exception $e) {
        Log::error('خطأ في تسجيل المستخدم', [
            'error' => $e->getMessage(),
            'phone' => $request->phone ?? null
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء إنشاء الحساب',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}






//register owner

/**
 * تسجيل مستخدم جديد (صاحب موقف) مع إمكانية رفع صورة شخصية اختيارية
 */
public function registerUserOwner(Request $request)
{
    try {
        // 1. التحقق من صحة البيانات
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'phone' => 'required|string|max:191|unique:users,phone|regex:/^09[0-9]{8}$/',
            'password' => 'required|string|min:8',
            'language' => 'sometimes|string|in:en,ar',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], [
            'name.required' => 'الاسم مطلوب',
            'name.string' => 'الاسم يجب أن يكون نصاً',
            'name.max' => 'الاسم لا يتجاوز 191 حرف',
            
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
            'phone.max' => 'رقم الهاتف لا يتجاوز 191 حرف',
            'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
            'phone.regex' => 'رقم الهاتف يجب أن يتألف من 10 أرقام ويبدأ بـ 09',
            
            'password.required' => 'كلمة المرور مطلوبة',
            'password.string' => 'كلمة المرور يجب أن تكون نصاً',
            'password.min' => 'كلمة المرور يجب أن تتجاوز 8 أحرف',
            
            'language.string' => 'اللغة يجب أن تكون نصاً',
            'language.in' => 'اللغة يجب أن تكون إما en أو ar',
            
            'image.image' => 'يجب أن تكون الصورة بصيغة صورة',
            'image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
            'image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
        ]);

        // 2. إنشاء المستخدم
        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'language' => $data['language'] ?? 'en',
        ]);

        // 3. رفع الصورة وحفظها (إذا وجدت)
        $uploadedImage = null;
        
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            
            if ($image && $image->isValid()) {
                // إنشاء اسم فريد للصورة
                $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                
                // حفظ الصورة في مجلد users
                $path = $image->storeAs('users', $imageName, 'public');
                
                // حفظ في جدول image_user باستخدام العلاقة المورف
                $uploadedImage = imageUser::create([
                    'path' => $path,
                    'title' => $user->name . '_profile_' . time(),
                    'imageable_type' => 'App\Models\User',
                    'imageable_id' => $user->id,
                ]);
            }
        }

        // 4. إنشاء دور للمستخدم (role = 2)
        $role = Role::create([
            'role' => 2,
            'user_id' => $user->id,
        ]);

        // 5. إنشاء التوكن
        $token = $user->createToken('ghaidaaProjectToken')->plainTextToken;

        // 6. تحميل العلاقة
        $user->load('role');

        // 7. تجهيز بيانات المستخدم للاستجابة
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'language' => $user->language,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'role' => $role,
            'image' => $uploadedImage,
        ];

        // 8. تجهيز الاستجابة
        $response = [
            'user' => $userData,
            'token' => $token,
        ];

        // 9. تسجيل العملية
        Log::info('تم تسجيل مستخدم جديد (صاحب موقف)', [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'has_image' => $uploadedImage ? true : false,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء حساب صاحب الموقف بنجاح',
            'data' => $response
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
        Log::error('خطأ في قاعدة البيانات أثناء تسجيل صاحب الموقف', [
            'error' => $e->getMessage(),
            'phone' => $request->phone ?? null
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ في قاعدة البيانات',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'database_error',
            'status_code' => 500
        ], 500);
        
    } catch (\Exception $e) {
        Log::error('خطأ في تسجيل صاحب الموقف', [
            'error' => $e->getMessage(),
            'phone' => $request->phone ?? null
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء إنشاء حساب صاحب الموقف',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}































//     //Register
    
// public function registerUserRenter(Request $request)
// {
//     try {
//         $data = $request->validate([
//             'name' => 'required|string|max:191',
//             'phone' => 'required|string|max:191|unique:users,phone|regex:/^09[0-9]{8}$/',
//             'password' => 'required|string|min:8',
//             'language' => 'sometimes|string|in:en,ar',
//         ], [
//             'name.required' => 'الاسم مطلوب',
//             'name.string' => 'الاسم يجب أن يكون نصاً',
//             'name.max' => 'الاسم لا يتجاوز 191 حرف',
            
//             'phone.required' => 'رقم الهاتف مطلوب',
//             'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
//             'phone.max' => 'رقم الهاتف لا يتجاوز 191 حرف',
//             'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
//             'phone.regex' => 'رقم الهاتف يجب أن يتألف من 10 أرقام ويبدأ بـ 09',
            
//             'password.required' => 'كلمة المرور مطلوبة',
//             'password.string' => 'كلمة المرور يجب أن تكون نصاً',
//             'password.min' => 'كلمة المرور يجب أن تتجاوز 8 أحرف',
            
//             'language.string' => 'اللغة يجب أن تكون نصاً',
//             'language.in' => 'اللغة يجب أن تكون إما en أو ar',
//         ]);

//         // إنشاء المستخدم
//         $user = User::create([
//             'name' => $data['name'],
//             'phone' => $data['phone'],
//             'password' => Hash::make($data['password']),
//             'language' => $data['language'] ?? 'en',
//         ]);

//         // إنشاء دور للمستخدم (role = 3)
//         $role = Role::create([
//             'role' => 3,
//             'user_id' => $user->id,
//         ]);

//         $token = $user->createToken('ghaidaaProjectToken')->plainTextToken;

//         $response = [
//             'user' => $user,
//             'role' => $role,
//             'token' => $token,
//         ];

//         return response($response, 201);

//     } catch (\Illuminate\Validation\ValidationException $e) {
//         return response()->json([
//             'errors' => $e->errors()
//         ], 422);
        
//     } catch (\Exception $e) {
//         return response()->json([
//             'error' => 'حدث خطأ أثناء إنشاء الحساب',
//             'message' => $e->getMessage()
//         ], 500);
//     }
// }

// ////////////////////////////////////////////////////////////
  
// public function registerUserOwner(Request $request)
// {
//     try {
//         $data = $request->validate([
//             'name' => 'required|string|max:191',
//             'phone' => 'required|string|max:191|unique:users,phone|regex:/^09[0-9]{8}$/',
//             'password' => 'required|string|min:8',
//             'language' => 'sometimes|string|in:en,ar',
//         ], [
//             'name.required' => 'الاسم مطلوب',
//             'name.string' => 'الاسم يجب أن يكون نصاً',
//             'name.max' => 'الاسم لا يتجاوز 191 حرف',
            
//             'phone.required' => 'رقم الهاتف مطلوب',
//             'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
//             'phone.max' => 'رقم الهاتف لا يتجاوز 191 حرف',
//             'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
//             'phone.regex' => 'رقم الهاتف يجب أن يتألف من 10 أرقام ويبدأ بـ 09',
            
//             'password.required' => 'كلمة المرور مطلوبة',
//             'password.string' => 'كلمة المرور يجب أن تكون نصاً',
//             'password.min' => 'كلمة المرور يجب أن تتجاوز 8 أحرف',
            
//             'language.string' => 'اللغة يجب أن تكون نصاً',
//             'language.in' => 'اللغة يجب أن تكون إما en أو ar',
//         ]);

//         // إنشاء المستخدم
//         $user = User::create([
//             'name' => $data['name'],
//             'phone' => $data['phone'],
//             'password' => Hash::make($data['password']),
//             'language' => $data['language'] ?? 'en',
//         ]);

//         // إنشاء دور للمستخدم (role = 3)
//         $role = Role::create([
//             'role' => 2,
//             'user_id' => $user->id,
//         ]);

//         $token = $user->createToken('ghaidaaProjectToken')->plainTextToken;

//         $response = [
//             'user' => $user,
//             'role' => $role,
//             'token' => $token,
//         ];

//         return response($response, 201);

//     } catch (\Illuminate\Validation\ValidationException $e) {
//         return response()->json([
//             'errors' => $e->errors()
//         ], 422);
        
//     } catch (\Exception $e) {
//         return response()->json([
//             'error' => 'حدث خطأ أثناء إنشاء الحساب',
//             'message' => $e->getMessage()
//         ], 500);
//     }
// }



// //Login

public function loginUser(Request $request)
{
    try {
        // التحقق من صحة البيانات المدخلة
        $data = $request->validate([
            'phone' => 'required|string|regex:/^09[0-9]{8}$/',
            'password' => 'required|string|min:8',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
            'phone.regex' => 'رقم الهاتف يجب أن يتألف من 10 أرقام ويبدأ بـ 09',
            
            'password.required' => 'كلمة المرور مطلوبة',
            'password.string' => 'كلمة المرور يجب أن تكون نصاً',
            'password.min' => 'كلمة المرور يجب أن تتجاوز 8 أحرف',
        ]);

        // البحث عن المستخدم برقم الهاتف
        $user = User::where('phone', $data['phone'])->first();

        // التحقق من وجود المستخدم
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'رقم الهاتف غير مسجل',
                'error_type' => 'user_not_found'
            ], 404);
        }

        // التحقق من كلمة المرور
        if (!Hash::check($data['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة المرور غير صحيحة',
                'error_type' => 'invalid_password'
            ], 401);
        }

        // التحقق من وجود دور للمستخدم
        $role = Role::where('user_id', $user->id)->first();
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم العثور على دور للمستخدم',
                'error_type' => 'role_not_found'
            ], 404);
        }

        // حذف التوكنات القديمة (اختياري)
        $user->tokens()->delete();

        // إنشاء توكن جديد
        $token = $user->createToken('ghaidaaProjectToken')->plainTextToken;

        // تحديث آخر تسجيل دخول (إذا كان لديك هذه الأعمدة)
        // $user->update([
        //     'last_login_at' => now(),
        //     'last_login_ip' => $request->ip(),
        // ]);

        // إرجاع الاستجابة الناجحة
        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'language' => $user->language,
                ],
                'role' => $role,
                'token' => $token,
                'token_type' => 'Bearer',
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        // معالجة أخطاء التحقق
        return response()->json([
            'success' => false,
            'message' => 'فشل التحقق من البيانات',
            'errors' => $e->errors(),
            'error_type' => 'validation_error'
        ], 422);

    } catch (\Illuminate\Database\QueryException $e) {
        // معالجة أخطاء قاعدة البيانات
        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ في قاعدة البيانات',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم',
            'error_type' => 'database_error'
        ], 500);

    } catch (\Exception $e) {
        // معالجة أي أخطاء أخرى
        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ غير متوقع',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم',
            'error_type' => 'general_error'
        ], 500);
    }
}




 //Logout


   public function logoutUser(Request $request)
    {
        try {
            // 1. التحقق من وجود مستخدم مسجل دخول
            if (!$request->user()) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                    'error_type' => 'unauthenticated',
                    'status_code' => 401
                ], 401);
            }

            // 2. التحقق من وجود توكن نشط
            $currentToken = $request->user()->currentAccessToken();
            
            if (!$currentToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد توكن نشط لتسجيل الخروج',
                    'error_type' => 'no_active_token',
                    'status_code' => 404
                ], 404);
            }

            // 3. التحقق من صلاحية التوكن
            if ($currentToken->expires_at && $currentToken->expires_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'التوكن منتهي الصلاحية',
                    'error_type' => 'token_expired',
                    'status_code' => 401
                ], 401);
            }

            // 4. التحقق من وجود المستخدم في قاعدة البيانات
            $user = User::find($request->user()->id);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'المستخدم غير موجود في قاعدة البيانات',
                    'error_type' => 'user_not_found',
                    'status_code' => 404
                ], 404);
            }

            // 5. التحقق من وجود دور للمستخدم
            $role = Role::where('user_id', $user->id)->first();
            
            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'لم يتم العثور على دور للمستخدم',
                    'error_type' => 'role_not_found',
                    'status_code' => 404
                ], 404);
            }

            // 6. تسجيل معلومات المستخدم قبل تسجيل الخروج
            try {
                Log::info('محاولة تسجيل خروج', [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'token_id' => $currentToken->id
                ]);
            } catch (\Exception $e) {
                // تجاهل أخطاء التسجيل
            }

            // 7. محاولة حذف التوكن
            try {
                $deleted = $currentToken->delete();
                
                if (!$deleted) {
                    return response()->json([
                        'success' => false,
                        'message' => 'فشل حذف التوكن من قاعدة البيانات',
                        'error_type' => 'token_deletion_failed',
                        'status_code' => 500
                    ], 500);
                }
            } catch (QueryException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'خطأ في قاعدة البيانات أثناء حذف التوكن',
                    'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                    'error_type' => 'database_error',
                    'status_code' => 500
                ], 500);
            }

            // 8. تسجيل نجاح عملية تسجيل الخروج
            try {
                Log::info('تم تسجيل الخروج بنجاح', [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'ip' => $request->ip()
                ]);
            } catch (\Exception $e) {
                // تجاهل أخطاء التسجيل
            }

            // 9. إرجاع الاستجابة الناجحة
            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الخروج بنجاح',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                    ],
                    'role' => $role,
                    'logged_out_at' => now()->toDateTimeString(),
                    'token_deleted' => true
                ]
            ], 200);

        } catch (QueryException $e) {
            // معالجة أخطاء قاعدة البيانات
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في قاعدة البيانات أثناء تسجيل الخروج',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم',
                'error_type' => 'database_error',
                'status_code' => 500
            ], 500);

        } catch (AuthenticationException $e) {
            // معالجة أخطاء المصادقة
            return response()->json([
                'success' => false,
                'message' => 'فشل المصادقة، يرجى تسجيل الدخول مرة أخرى',
                'error_type' => 'authentication_failed',
                'status_code' => 401
            ], 401);

        } catch (ValidationException $e) {
            // معالجة أخطاء التحقق
            return response()->json([
                'success' => false,
                'message' => 'فشل التحقق من البيانات',
                'errors' => $e->errors(),
                'error_type' => 'validation_error',
                'status_code' => 422
            ], 422);

        } catch (HttpException $e) {
            // معالجة أخطاء HTTP
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'http_error',
                'status_code' => $e->getStatusCode()
            ], $e->getStatusCode());

        } catch (RuntimeException $e) {
            // معالجة أخطاء Runtime
            return response()->json([
                'success' => false,
                'message' => 'خطأ في تنفيذ العملية',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'runtime_error',
                'status_code' => 500
            ], 500);

        } catch (ErrorException $e) {
            // معالجة أخطاء PHP
            return response()->json([
                'success' => false,
                'message' => 'خطأ في النظام',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'php_error',
                'status_code' => 500
            ], 500);

        } catch (\Exception $e) {
            // معالجة أي أخطاء أخرى غير متوقعة
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ غير متوقع أثناء تسجيل الخروج',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم',
                'error_type' => 'general_error',
                'status_code' => 500,
                'trace' => config('app.debug') ? $e->getTrace() : null
            ], 500);
        }
    }
}

