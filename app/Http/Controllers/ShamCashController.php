<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sham_cash;
use App\Models\ImageShamCash;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class ShamCashController extends Controller
{
    /**
     * إدخال معلومات الشام كاش (الاسم وصورة QR)
     */
    public function storeShamCash(Request $request)
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
                'name_in_sham' => 'required|string|max:191',
                'qr_image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            ], [
                'name_in_sham.required' => 'الاسم في الشام كاش مطلوب',
                'name_in_sham.string' => 'الاسم يجب أن يكون نصاً',
                'name_in_sham.max' => 'الاسم لا يتجاوز 191 حرف',
                
                'qr_image.required' => 'صورة QR مطلوبة',
                'qr_image.image' => 'يجب أن تكون الصورة بصيغة صورة',
                'qr_image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
                'qr_image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
            ]);

            // 3. التحقق من وجود معلومات شام كاش سابقة للمستخدم
            $existingShamCash = Sham_cash::where('user_id', $user->id)->first();
            
            if ($existingShamCash) {
                // حذف الصورة القديمة
                $oldImage = ImageShamCash::where('imageable_id', $existingShamCash->id)
                    ->where('imageable_type', 'App\Models\ShamCash')
                    ->first();
                
                if ($oldImage) {
                    if (Storage::disk('public')->exists($oldImage->path)) {
                        Storage::disk('public')->delete($oldImage->path);
                    }
                    $oldImage->delete();
                }
                
                // حذف السجل القديم
                $existingShamCash->delete();
            }

            // 4. حفظ معلومات الشام كاش
            $shamCash = Sham_cash::create([
                'user_id' => $user->id,
                'name_in_sham' => $request->name_in_sham,
            ]);

            // 5. رفع صورة QR
            $uploadedImage = null;
            
            if ($request->hasFile('qr_image')) {
                $image = $request->file('qr_image');
                
                if ($image && $image->isValid()) {
                    // إنشاء اسم فريد للصورة
                    $imageName = time() . '_qr_' . uniqid() . '.' . $image->getClientOriginalExtension();
                    
                    // حفظ الصورة في مجلد sham_cash
                    $path = $image->storeAs('sham_cash', $imageName, 'public');
                    
                    // حفظ في جدول image_sham_cash
                    $uploadedImage = ImageShamCash::create([
                        'path' => $path,
                        'title' => $user->name . '_qr_' . time(),
                        'imageable_type' => 'App\Models\ShamCash',
                        'imageable_id' => $shamCash->id,
                    ]);
                }
            }

            // 6. تسجيل العملية
            Log::info('تم إدخال معلومات الشام كاش', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'sham_cash_id' => $shamCash->id,
                'name_in_sham' => $request->name_in_sham,
                'has_qr_image' => $uploadedImage ? true : false,
                'ip' => $request->ip()
            ]);

            // 7. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => 'تم إدخال معلومات الشام كاش بنجاح',
                'data' => [
                    'sham_cash' => $shamCash,
                    'qr_image' => $uploadedImage,
                    'qr_image_url' => $uploadedImage ? asset('storage/' . $uploadedImage->path) : null,
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
    public function getShamCash(Request $request)
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

            $shamCash = Sham_cash::where('user_id', $user->id)->first();

            if (!$shamCash) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد معلومات شام كاش',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // جلب صورة QR
            $qrImage = ImageShamCash::where('imageable_id', $shamCash->id)
                ->where('imageable_type', 'App\Models\ShamCash')
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب معلومات الشام كاش بنجاح',
                'data' => [
                    'sham_cash' => $shamCash,
                    'qr_image' => $qrImage,
                    'qr_image_url' => $qrImage ? asset('storage/' . $qrImage->path) : null,
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



















////////////////////


    /**
     * تحديث معلومات الشام كاش (الاسم و/أو صورة QR)
     * يمكن تحديث الاسم فقط، أو الصورة فقط، أو كليهما معاً
     */
    public function updateShamCash(Request $request)
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

            // 2. البحث عن معلومات الشام كاش للمستخدم
            $shamCash = Sham_cash::where('user_id', $user->id)->first();

            if (!$shamCash) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد معلومات شام كاش لتحديثها',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // 3. التحقق من وجود بيانات للتحديث
            $hasName = $request->has('name_in_sham');
            $hasImage = $request->hasFile('qr_image');

            if (!$hasName && !$hasImage) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب إدخال اسم أو صورة QR للتحديث',
                    'error_type' => 'no_data_provided',
                    'status_code' => 400
                ], 400);
            }

            // 4. التحقق من صحة البيانات
            $rules = [];
            $messages = [];

            if ($hasName) {
                $rules['name_in_sham'] = 'required|string|max:191';
                $messages['name_in_sham.required'] = 'الاسم مطلوب';
                $messages['name_in_sham.string'] = 'الاسم يجب أن يكون نصاً';
                $messages['name_in_sham.max'] = 'الاسم لا يتجاوز 191 حرف';
            }

            if ($hasImage) {
                $rules['qr_image'] = 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048';
                $messages['qr_image.required'] = 'صورة QR مطلوبة';
                $messages['qr_image.image'] = 'يجب أن تكون الصورة بصيغة صورة';
                $messages['qr_image.mimes'] = 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg';
                $messages['qr_image.max'] = 'حجم الصورة يجب أن لا يتجاوز 2MB';
            }

            $request->validate($rules, $messages);

            // 5. تخزين البيانات القديمة للتسجيل
            $oldData = [
                'name_in_sham' => $shamCash->name_in_sham,
            ];

            $oldImage = ImageShamCash::where('imageable_id', $shamCash->id)
                ->where('imageable_type', 'App\Models\ShamCash')
                ->first();

            if ($oldImage) {
                $oldData['qr_image_path'] = $oldImage->path;
            }

            // 6. تحديث البيانات
            $updatedFields = [];

            // تحديث الاسم
            if ($hasName) {
                // التحقق من أن الاسم الجديد مختلف عن القديم
                if ($shamCash->name_in_sham === $request->name_in_sham) {
                    return response()->json([
                        'success' => false,
                        'message' => 'الاسم الجديد هو نفس الاسم الحالي',
                        'error_type' => 'same_name',
                        'status_code' => 400
                    ], 400);
                }

                $shamCash->name_in_sham = $request->name_in_sham;
                $shamCash->save();
                $updatedFields[] = 'name';
            }

            // تحديث صورة QR
            $uploadedImage = null;
            if ($hasImage) {
                // حذف الصورة القديمة
                if ($oldImage) {
                    if (Storage::disk('public')->exists($oldImage->path)) {
                        Storage::disk('public')->delete($oldImage->path);
                    }
                    $oldImage->delete();
                }

                // رفع الصورة الجديدة
                $image = $request->file('qr_image');
                
                if ($image && $image->isValid()) {
                    $imageName = time() . '_qr_' . uniqid() . '.' . $image->getClientOriginalExtension();
                    $path = $image->storeAs('sham_cash', $imageName, 'public');
                    
                    $uploadedImage = ImageShamCash::create([
                        'path' => $path,
                        'title' => $user->name . '_qr_' . time(),
                        'imageable_type' => 'App\Models\ShamCash',
                        'imageable_id' => $shamCash->id,
                    ]);
                    
                    $updatedFields[] = 'qr_image';
                }
            }

            // 7. جلب الصورة المحدثة
            $qrImage = ImageShamCash::where('imageable_id', $shamCash->id)
                ->where('imageable_type', 'App\Models\ShamCash')
                ->first();

            // 8. تحديد رسالة النجاح
            $message = '';
            if (count($updatedFields) === 2) {
                $message = 'تم تحديث الاسم وصورة QR بنجاح';
            } elseif (in_array('name', $updatedFields)) {
                $message = 'تم تحديث الاسم بنجاح';
            } elseif (in_array('qr_image', $updatedFields)) {
                $message = 'تم تحديث صورة QR بنجاح';
            }

            // 9. تسجيل العملية
            Log::info('تم تحديث معلومات الشام كاش', [
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'sham_cash_id' => $shamCash->id,
                'updated_fields' => $updatedFields,
                'old_data' => $oldData,
                'ip' => $request->ip()
            ]);

            // 10. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'sham_cash' => $shamCash,
                    'qr_image' => $qrImage,
                    'qr_image_url' => $qrImage ? asset('storage/' . $qrImage->path) : null,
                    'updated_fields' => $updatedFields,
                    'old_data' => $oldData,
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
            Log::error('خطأ في قاعدة البيانات أثناء تحديث الشام كاش', [
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
            Log::error('خطأ في تحديث الشام كاش', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديث معلومات الشام كاش',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }





}