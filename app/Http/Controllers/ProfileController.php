<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\vehicle;
use App\Models\Address;
use App\Models\Image_documents;
use Illuminate\Support\Facades\Storage;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class ProfileController extends Controller
{
    /**
     * إكمال المعلومات الشخصية (السيارة والعنوان)
     */
    public function completeProfile(Request $request)
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

            // 2. التحقق من صحة البيانات
            $request->validate([
                // بيانات السيارة
                'plate_number' => 'required|string|max:20|unique:vehicle,plate_number',
                'vehicle_type' => 'required|string|max:50',
                'vehicle_color' => 'required|string|max:30',
                
                // بيانات العنوان
                'city' => 'required|string|max:100',
                'street' => 'required|string|max:191',
                'neighborhood' => 'required|string|max:100',
            ], [
                // رسائل خطأ السيارة
                'plate_number.required' => 'رقم اللوحة مطلوب',
                'plate_number.string' => 'رقم اللوحة يجب أن يكون نصاً',
                'plate_number.max' => 'رقم اللوحة لا يتجاوز 20 حرف',
                'plate_number.unique' => 'رقم اللوحة مستخدم بالفعل',
                
                'vehicle_type.required' => 'نوع السيارة مطلوب',
                'vehicle_type.string' => 'نوع السيارة يجب أن يكون نصاً',
                'vehicle_type.max' => 'نوع السيارة لا يتجاوز 50 حرف',
                
                'vehicle_color.required' => 'لون السيارة مطلوب',
                'vehicle_color.string' => 'لون السيارة يجب أن يكون نصاً',
                'vehicle_color.max' => 'لون السيارة لا يتجاوز 30 حرف',
                
                // رسائل خطأ العنوان
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

            // 3. التحقق من عدم وجود سيارة مسجلة بالفعل للمستخدم
            $existingVehicle = vehicle::where('id_user', $user->id)->first();
            if ($existingVehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'لديك سيارة مسجلة بالفعل',
                    'error_type' => 'vehicle_exists',
                    'data' => $existingVehicle,
                    'status_code' => 400
                ], 400);
            }

            // 4. التحقق من عدم وجود عنوان مسجل بالفعل للمستخدم
            $existingAddress = Address::where('user_id', $user->id)->first();
            if ($existingAddress) {
                return response()->json([
                    'success' => false,
                    'message' => 'لديك عنوان مسجل بالفعل',
                    'error_type' => 'address_exists',
                    'data' => $existingAddress,
                    'status_code' => 400
                ], 400);
            }

            // 5. استخدام transaction لضمان完整性
            DB::beginTransaction();
            
            try {
                // 5.1 إنشاء بيانات السيارة
                $vehicle = vehicle::create([
                    'id_user' => $user->id,
                    'plate_number' => $request->plate_number,
                    'vehicle_type' => $request->vehicle_type,
                    'vehicle_color' => $request->vehicle_color,
                ]);

                // 5.2 إنشاء بيانات العنوان
                $address = Address::create([
                    'user_id' => $user->id,
                    'city' => $request->city,
                    'street' => $request->street,
                    'neighborhood' => $request->neighborhood,
                ]);

                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            // 6. تحميل العلاقات
            $user->load('role');

            // 7. تسجيل العملية
            Log::info('تم إكمال المعلومات الشخصية', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'vehicle_id' => $vehicle->id,
                'address_id' => $address->id,
                'ip' => $request->ip()
            ]);

            // 8. إرجاع الاستجابة الناجحة
            return response()->json([
                'success' => true,
                'message' => 'تم إكمال المعلومات الشخصية بنجاح',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'language' => $user->language,
                    ],
                    'vehicle' => $vehicle,
                    'address' => $address,
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
            Log::error('خطأ في قاعدة البيانات أثناء إكمال المعلومات الشخصية', [
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
            Log::error('خطأ في إكمال المعلومات الشخصية', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إكمال المعلومات الشخصية',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }

    /**
     * عرض الملف الشخصي الكامل
     */
    public function getProfile(Request $request)
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

            // تحميل جميع العلاقات
            $user->load(['role', 'vehicle', 'address']);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الملف الشخصي بنجاح',
                'data' => $user
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
     * تحديث معلومات السيارة
     */
    public function updateVehicle(Request $request)
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

            $vehicle = vehicle::where('id_user', $user->id)->first();
            
            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد سيارة مسجلة',
                    'error_type' => 'vehicle_not_found',
                    'status_code' => 404
                ], 404);
            }

            $request->validate([
                'plate_number' => 'sometimes|string|max:20|unique:vehicle,plate_number,' . $vehicle->id,
                'vehicle_type' => 'sometimes|string|max:50',
                'vehicle_color' => 'sometimes|string|max:30',
            ]);

            $vehicle->update($request->only(['plate_number', 'vehicle_type', 'vehicle_color']));

            Log::info('تم تحديث معلومات السيارة', [
                'user_id' => $user->id,
                'vehicle_id' => $vehicle->id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث معلومات السيارة بنجاح',
                'data' => $vehicle
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
     * تحديث معلومات العنوان
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
                    'message' => 'لا يوجد عنوان مسجل',
                    'error_type' => 'address_not_found',
                    'status_code' => 404
                ], 404);
            }

            $request->validate([
                'city' => 'sometimes|string|max:100',
                'street' => 'sometimes|string|max:191',
                'neighborhood' => 'sometimes|string|max:100',
            ]);

            $address->update($request->only(['city', 'street', 'neighborhood']));

            Log::info('تم تحديث معلومات العنوان', [
                'user_id' => $user->id,
                'address_id' => $address->id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث معلومات العنوان بنجاح',
                'data' => $address
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
     * حذف السيارة
     */
    public function deleteVehicle(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك',
                    'error_type' => 'unauthenticated'
                ], 401);
            }

            $vehicle = vehicle::where('id_user', $user->id)->first();
            
            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد سيارة مسجلة',
                    'error_type' => 'vehicle_not_found'
                ], 404);
            }

            $vehicle->delete();

            Log::info('تم حذف السيارة', [
                'user_id' => $user->id,
                'vehicle_id' => $vehicle->id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف السيارة بنجاح'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    ///////////////////////////////////////////
   /**
     * رفع صور الهوية (الوجه الأمامي والخلفي)
     */
    public function uploadIdentityDocuments(Request $request)
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

            // 2. التحقق من صحة البيانات
            $request->validate([
                'front_image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'back_image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'document_type' => 'sometimes|string|in:id_card,passport,driving_license',
            ], [
                'front_image.required' => 'صورة الوجه الأمامي للهوية مطلوبة',
                'front_image.image' => 'يجب أن تكون الصورة بصيغة صورة',
                'front_image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
                'front_image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
                
                'back_image.required' => 'صورة الوجه الخلفي للهوية مطلوبة',
                'back_image.image' => 'يجب أن تكون الصورة بصيغة صورة',
                'back_image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
                'back_image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
                
                'document_type.in' => 'نوع الوثيقة يجب أن يكون: id_card, passport, driving_license',
            ]);

            // 3. حذف الصور القديمة (إذا وجدت)
            $existingDocuments = Image_documents::where('imageable_id', $user->id)
                ->where('imageable_type', 'App\Models\User')
                ->get();

            foreach ($existingDocuments as $doc) {
                if (Storage::disk('public')->exists($doc->path)) {
                    Storage::disk('public')->delete($doc->path);
                }
                $doc->delete();
            }

            // 4. رفع الصور وحفظها
            $uploadedImages = [];

            // رفع الصورة الأمامية
            if ($request->hasFile('front_image')) {
                $frontImage = $request->file('front_image');
                $frontName = time() . '_front_' . uniqid() . '.' . $frontImage->getClientOriginalExtension();
                $frontPath = $frontImage->storeAs('documents', $frontName, 'public');
                
                $frontDocument = Image_documents::create([
                    'path' => $frontPath,
                    'title' => $user->name . '_front_' . time(),
                    'imageable_type' => 'App\Models\User',
                    'imageable_id' => $user->id,
                ]);
                
                $uploadedImages['front'] = $frontDocument;
            }

            // رفع الصورة الخلفية
            if ($request->hasFile('back_image')) {
                $backImage = $request->file('back_image');
                $backName = time() . '_back_' . uniqid() . '.' . $backImage->getClientOriginalExtension();
                $backPath = $backImage->storeAs('documents', $backName, 'public');
                
                $backDocument = Image_documents::create([
                    'path' => $backPath,
                    'title' => $user->name . '_back_' . time(),
                    'imageable_type' => 'App\Models\User',
                    'imageable_id' => $user->id,
                ]);
                
                $uploadedImages['back'] = $backDocument;
            }

            // 5. تسجيل العملية
            Log::info('تم رفع صور الهوية', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'front_image' => $uploadedImages['front']->path ?? null,
                'back_image' => $uploadedImages['back']->path ?? null,
                'ip' => $request->ip()
            ]);

            // 6. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => 'تم رفع صور الهوية بنجاح',
                'data' => [
                    'front_image' => $uploadedImages['front'] ?? null,
                    'front_image_url' => isset($uploadedImages['front']) ? asset('storage/' . $uploadedImages['front']->path) : null,
                    'back_image' => $uploadedImages['back'] ?? null,
                    'back_image_url' => isset($uploadedImages['back']) ? asset('storage/' . $uploadedImages['back']->path) : null,
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
            Log::error('خطأ في قاعدة البيانات أثناء رفع صور الهوية', [
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
            Log::error('خطأ في رفع صور الهوية', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء رفع صور الهوية',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }

    /**
     * عرض صور الهوية للمستخدم الحالي
     */
    public function getIdentityDocuments(Request $request)
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

            $documents = Image_documents::where('imageable_id', $user->id)
                ->where('imageable_type', 'App\Models\User')
                ->get();

            if ($documents->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد صور هوية مرفوعة',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // تقسيم الصور إلى أمامية وخلفية
            $frontImage = $documents->firstWhere('title', 'like', '%front%');
            $backImage = $documents->firstWhere('title', 'like', '%back%');

            return response()->json([
                'success' => true,
                'message' => 'تم جلب صور الهوية بنجاح',
                'data' => [
                    'front_image' => $frontImage,
                    'front_image_url' => $frontImage ? asset('storage/' . $frontImage->path) : null,
                    'back_image' => $backImage,
                    'back_image_url' => $backImage ? asset('storage/' . $backImage->path) : null,
                    'all_images' => $documents,
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
     * حذف صور الهوية
     */
    public function deleteIdentityDocuments(Request $request)
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

            $documents = Image_documents::where('imageable_id', $user->id)
                ->where('imageable_type', 'App\Models\User')
                ->get();

            if ($documents->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد صور هوية لحذفها',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // حذف الملفات من التخزين
            foreach ($documents as $doc) {
                if (Storage::disk('public')->exists($doc->path)) {
                    Storage::disk('public')->delete($doc->path);
                }
                $doc->delete();
            }

            Log::info('تم حذف صور الهوية', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف صور الهوية بنجاح'
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



