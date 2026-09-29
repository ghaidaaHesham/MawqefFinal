<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ShamAdminAndOwner;
use App\Models\ImageShamAdminAndOwner;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class ShamAdminAndOwnerController extends Controller
{
    /**
     * إضافة معلومات الشام كاش (الاسم والصورة)
     * يمكن للمدير (Admin) أو صاحب الموقف (Owner) الإضافة
     */
    public function store(Request $request)
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

            // 2. التحقق من أن المستخدم هو Admin أو Owner
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
                    'message' => 'غير مصرح لك، فقط المدير أو صاحب الموقف يمكنه إضافة معلومات الشام كاش',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 3. التحقق من صحة البيانات
            $request->validate([
                'name_in_sham' => 'required|string|max:191',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            ], [
                'name_in_sham.required' => 'الاسم في الشام كاش مطلوب',
                'name_in_sham.string' => 'الاسم يجب أن يكون نصاً',
                'name_in_sham.max' => 'الاسم لا يتجاوز 191 حرف',
                
                'image.required' => 'الصورة مطلوبة',
                'image.image' => 'يجب أن تكون الصورة بصيغة صورة',
                'image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
                'image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
            ]);

            // 4. التحقق من وجود معلومات شام كاش سابقة للمستخدم
            $existingSham = ShamAdminAndOwner::where('user_id', $user->id)->first();
            
            if ($existingSham) {
                // حذف الصورة القديمة
                $oldImage = ImageShamAdminAndOwner::where('imageable_id', $existingSham->id)
                    ->where('imageable_type', 'App\Models\ShamAdminAndOwner')
                    ->first();
                
                if ($oldImage) {
                    if (Storage::disk('public')->exists($oldImage->path)) {
                        Storage::disk('public')->delete($oldImage->path);
                    }
                    $oldImage->delete();
                }
                
                // حذف السجل القديم
                $existingSham->delete();
            }

            // 5. حفظ معلومات الشام كاش
            $sham = ShamAdminAndOwner::create([
                'user_id' => $user->id,
                'name_in_sham' => $request->name_in_sham,
            ]);

            // 6. رفع الصورة
            $uploadedImage = null;
            
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                
                if ($image && $image->isValid()) {
                    // إنشاء اسم فريد للصورة
                    $imageName = time() . '_sham_' . uniqid() . '.' . $image->getClientOriginalExtension();
                    
                    // حفظ الصورة في مجلد sham_admin_owner
                    $path = $image->storeAs('sham_admin_owner', $imageName, 'public');
                    
                    // حفظ في جدول imageshamadminowner
                    $uploadedImage = ImageShamAdminAndOwner::create([
                        'path' => $path,
                        'title' => $user->name . '_sham_' . time(),
                        'imageable_type' => 'App\Models\ShamAdminAndOwner',
                        'imageable_id' => $sham->id,
                    ]);
                }
            }

            // 7. تحميل العلاقات
            $sham->load('user', 'images');

            // 8. تحديد نوع المستخدم للرسالة
            $userType = $isAdmin ? 'المدير' : 'صاحب الموقف';

            // 9. تسجيل العملية
            Log::info('تم إدخال معلومات الشام كاش', [
                'user_id' => $user->id,
                'user_type' => $userType,
                'name' => $user->name,
                'phone' => $user->phone,
                'sham_id' => $sham->id,
                'name_in_sham' => $request->name_in_sham,
                'has_image' => $uploadedImage ? true : false,
                'ip' => $request->ip()
            ]);

            // 10. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => "تم إدخال معلومات الشام كاش بواسطة {$userType} بنجاح",
                'data' => [
                    'sham' => $sham,
                    'image' => $uploadedImage,
                    'image_url' => $uploadedImage ? asset('storage/' . $uploadedImage->path) : null,
                    'added_by' => $userType,
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
            Log::error('خطأ في قاعدة البيانات أثناء إدخال معلومات الشام كاش', [
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
            Log::error('خطأ في إدخال معلومات الشام كاش', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إدخال معلومات الشام كاش',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }

    /**
     * عرض معلومات الشام كاش للمستخدم الحالي
     */
    public function getMySham(Request $request)
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

            $sham = ShamAdminAndOwner::where('user_id', $user->id)
                ->with(['user', 'images'])
                ->first();

            if (!$sham) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد معلومات شام كاش',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            $image = ImageShamAdminAndOwner::where('imageable_id', $sham->id)
                ->where('imageable_type', 'App\Models\ShamAdminAndOwner')
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب معلومات الشام كاش بنجاح',
                'data' => [
                    'sham' => $sham,
                    'image' => $image,
                    'image_url' => $image ? asset('storage/' . $image->path) : null,
                ]
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
     * عرض معلومات الشام كاش لمستخدم محدد (للمدير فقط)
     */
    public function getShamByUser(Request $request, $userId)
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

            // التحقق من أن المستخدم هو Admin
            $userRole = Role::where('user_id', $user->id)->first();
            
            if (!$userRole || $userRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط المدير يمكنه عرض معلومات الشام كاش للمستخدمين',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            $targetUser = User::find($userId);
            
            if (!$targetUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'المستخدم غير موجود',
                    'error_type' => 'user_not_found',
                    'status_code' => 404
                ], 404);
            }

            $sham = ShamAdminAndOwner::where('user_id', $userId)
                ->with(['user', 'images'])
                ->first();

            if (!$sham) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد معلومات شام كاش لهذا المستخدم',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            $image = ImageShamAdminAndOwner::where('imageable_id', $sham->id)
                ->where('imageable_type', 'App\Models\ShamAdminAndOwner')
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب معلومات الشام كاش للمستخدم بنجاح',
                'data' => [
                    'user' => [
                        'id' => $targetUser->id,
                        'name' => $targetUser->name,
                        'phone' => $targetUser->phone,
                    ],
                    'sham' => $sham,
                    'image' => $image,
                    'image_url' => $image ? asset('storage/' . $image->path) : null,
                ]
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
     * عرض جميع معلومات الشام كاش (للمدير فقط)
     */
    public function getAllSham(Request $request)
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

            // التحقق من أن المستخدم هو Admin
            $userRole = Role::where('user_id', $user->id)->first();
            
            if (!$userRole || $userRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط المدير يمكنه عرض جميع معلومات الشام كاش',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            $shamList = ShamAdminAndOwner::with(['user', 'images'])
                ->orderBy('created_at', 'desc')
                ->get();

            if ($shamList->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'لا توجد معلومات شام كاش',
                    'data' => [],
                    'count' => 0
                ], 200);
            }

            // تنسيق البيانات
            $formattedData = $shamList->map(function ($sham) {
                $image = ImageShamAdminAndOwner::where('imageable_id', $sham->id)
                    ->where('imageable_type', 'App\Models\ShamAdminAndOwner')
                    ->first();

                return [
                    'id' => $sham->id,
                    'user_id' => $sham->user_id,
                    'name_in_sham' => $sham->name_in_sham,
                    'created_at' => $sham->created_at,
                    'updated_at' => $sham->updated_at,
                    'image' => $image,
                    'image_url' => $image ? asset('storage/' . $image->path) : null,
                    'user' => $sham->user ? [
                        'id' => $sham->user->id,
                        'name' => $sham->user->name,
                        'phone' => $sham->user->phone,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'تم جلب جميع معلومات الشام كاش بنجاح',
                'data' => $formattedData,
                'count' => $shamList->count()
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
     * تحديث معلومات الشام كاش
     */
    public function update(Request $request)
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

            $sham = ShamAdminAndOwner::where('user_id', $user->id)->first();

            if (!$sham) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد معلومات شام كاش لتحديثها',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            $hasName = $request->has('name_in_sham');
            $hasImage = $request->hasFile('image');

            if (!$hasName && !$hasImage) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب إدخال اسم أو صورة للتحديث',
                    'error_type' => 'no_data_provided',
                    'status_code' => 400
                ], 400);
            }

            $rules = [];
            $messages = [];

            if ($hasName) {
                $rules['name_in_sham'] = 'required|string|max:191';
                $messages['name_in_sham.required'] = 'الاسم مطلوب';
                $messages['name_in_sham.string'] = 'الاسم يجب أن يكون نصاً';
                $messages['name_in_sham.max'] = 'الاسم لا يتجاوز 191 حرف';
            }

            if ($hasImage) {
                $rules['image'] = 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048';
                $messages['image.required'] = 'الصورة مطلوبة';
                $messages['image.image'] = 'يجب أن تكون الصورة بصيغة صورة';
                $messages['image.mimes'] = 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg';
                $messages['image.max'] = 'حجم الصورة يجب أن لا يتجاوز 2MB';
            }

            $request->validate($rules, $messages);

            $updatedFields = [];

            // تحديث الاسم
            if ($hasName) {
                if ($sham->name_in_sham === $request->name_in_sham) {
                    return response()->json([
                        'success' => false,
                        'message' => 'الاسم الجديد هو نفس الاسم الحالي',
                        'error_type' => 'same_name',
                        'status_code' => 400
                    ], 400);
                }

                $sham->name_in_sham = $request->name_in_sham;
                $sham->save();
                $updatedFields[] = 'name';
            }

            // تحديث الصورة
            if ($hasImage) {
                $oldImage = ImageShamAdminAndOwner::where('imageable_id', $sham->id)
                    ->where('imageable_type', 'App\Models\ShamAdminAndOwner')
                    ->first();
                
                if ($oldImage) {
                    if (Storage::disk('public')->exists($oldImage->path)) {
                        Storage::disk('public')->delete($oldImage->path);
                    }
                    $oldImage->delete();
                }

                $image = $request->file('image');
                $imageName = time() . '_sham_' . uniqid() . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('sham_admin_owner', $imageName, 'public');
                
                $uploadedImage = ImageShamAdminAndOwner::create([
                    'path' => $path,
                    'title' => $user->name . '_sham_' . time(),
                    'imageable_type' => 'App\Models\ShamAdminAndOwner',
                    'imageable_id' => $sham->id,
                ]);
                
                $updatedFields[] = 'image';
            }

            $sham->load('user', 'images');

            $image = ImageShamAdminAndOwner::where('imageable_id', $sham->id)
                ->where('imageable_type', 'App\Models\ShamAdminAndOwner')
                ->first();

            $message = '';
            if (count($updatedFields) === 2) {
                $message = 'تم تحديث الاسم والصورة بنجاح';
            } elseif (in_array('name', $updatedFields)) {
                $message = 'تم تحديث الاسم بنجاح';
            } elseif (in_array('image', $updatedFields)) {
                $message = 'تم تحديث الصورة بنجاح';
            }

            Log::info('تم تحديث معلومات الشام كاش', [
                'user_id' => $user->id,
                'sham_id' => $sham->id,
                'updated_fields' => $updatedFields,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'sham' => $sham,
                    'image' => $image,
                    'image_url' => $image ? asset('storage/' . $image->path) : null,
                    'updated_fields' => $updatedFields,
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
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ',
                'error' => $e->getMessage()
            ], 500);
        }
    }




    

    
}