<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Parking_spot;
use App\Models\Image;
use App\Models\Image_documents;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class ParkingSpotController extends Controller
{
    public function storeParking(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                    'error_type' => 'unauthenticated',
                    'status_code' => 401
                ], 401);
            }

            $userRole = Role::where('user_id', $user->id)->first();
            
            if (!$userRole || $userRole->role != 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط أصحاب المواقف يمكنهم إضافة مواقف',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 1. التحقق من صحة البيانات
            $request->validate([
                'title' => 'required|string|max:191',
                'address' => 'required|string|max:255',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'capacity' => 'required|integer|min:1',
                'price_per_hour' => 'nullable|numeric|min:0',
                'price_per_day' => 'nullable|numeric|min:0',
                'price_per_month' => 'nullable|numeric|min:0',
                'time_from' => 'required|date_format:H:i',
                'time_to' => 'required|date_format:H:i|after:time_from',
                'cancellation_policy' => 'nullable|string|max:500',
                
                // work_days مطلوب
                'work_days' => 'required|array|min:1',
                'work_days.*' => 'string|in:saturday,sunday,monday,tuesday,wednesday,thursday,friday',
                
                'images' => 'nullable|array',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                
                'ownership_document' => 'required|file|mimes:pdf,jpeg,png,jpg|max:5120',
                'municipality_license' => 'required|file|mimes:pdf,jpeg,png,jpg|max:5120',
            ], [
                'title.required' => 'عنوان الموقف مطلوب',
                'title.string' => 'العنوان يجب أن يكون نصاً',
                'title.max' => 'العنوان لا يتجاوز 191 حرف',
                
                'address.required' => 'العنوان التفصيلي مطلوب',
                'address.string' => 'العنوان التفصيلي يجب أن يكون نصاً',
                'address.max' => 'العنوان التفصيلي لا يتجاوز 255 حرف',
                
                'latitude.required' => 'خط العرض مطلوب',
                'latitude.numeric' => 'خط العرض يجب أن يكون رقماً',
                'latitude.between' => 'خط العرض يجب أن يكون بين -90 و 90',
                
                'longitude.required' => 'خط الطول مطلوب',
                'longitude.numeric' => 'خط الطول يجب أن يكون رقماً',
                'longitude.between' => 'خط الطول يجب أن يكون بين -180 و 180',
                
                'capacity.required' => 'السعة مطلوبة',
                'capacity.integer' => 'السعة يجب أن تكون رقماً صحيحاً',
                'capacity.min' => 'السعة يجب أن تكون 1 على الأقل',
                
                'price_per_hour.numeric' => 'السعر للساعة يجب أن يكون رقماً',
                'price_per_hour.min' => 'السعر للساعة يجب أن يكون 0 أو أكثر',
                
                'price_per_day.numeric' => 'السعر لليوم يجب أن يكون رقماً',
                'price_per_day.min' => 'السعر لليوم يجب أن يكون 0 أو أكثر',
                
                'price_per_month.numeric' => 'السعر للشهر يجب أن يكون رقماً',
                'price_per_month.min' => 'السعر للشهر يجب أن يكون 0 أو أكثر',
                
                'time_from.required' => 'وقت البداية مطلوب',
                'time_from.date_format' => 'وقت البداية يجب أن يكون بصيغة HH:MM',
                
                'time_to.required' => 'وقت النهاية مطلوب',
                'time_to.date_format' => 'وقت النهاية يجب أن يكون بصيغة HH:MM',
                'time_to.after' => 'وقت النهاية يجب أن يكون بعد وقت البداية',
                
                'work_days.required' => 'أيام العمل مطلوبة',
                'work_days.array' => 'أيام العمل يجب أن تكون مصفوفة',
                'work_days.min' => 'يجب إدخال يوم عمل واحد على الأقل',
                'work_days.*.in' => 'أيام العمل غير صحيحة، يجب أن تكون: saturday, sunday, monday, tuesday, wednesday, thursday, friday',
                
                'images.array' => 'الصور يجب أن تكون مصفوفة',
                'images.*.image' => 'يجب أن تكون الصور بصيغة صورة',
                'images.*.mimes' => 'الصور يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
                'images.*.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
                
                'ownership_document.required' => 'سند الملكية مطلوب',
                'ownership_document.file' => 'يجب أن يكون سند الملكية ملفاً',
                'ownership_document.mimes' => 'سند الملكية يجب أن يكون بصيغة: pdf, jpeg, png, jpg',
                'ownership_document.max' => 'حجم سند الملكية يجب أن لا يتجاوز 5MB',
                
                'municipality_license.required' => 'رخصة البلدية مطلوبة',
                'municipality_license.file' => 'يجب أن تكون رخصة البلدية ملفاً',
                'municipality_license.mimes' => 'رخصة البلدية يجب أن تكون بصيغة: pdf, jpeg, png, jpg',
                'municipality_license.max' => 'حجم رخصة البلدية يجب أن لا يتجاوز 5MB',
            ]);

            // 2. التحقق من وجود سعر واحد على الأقل
            $hasPrice = $request->has('price_per_hour') || 
                        $request->has('price_per_day') || 
                        $request->has('price_per_month');
            
            if (!$hasPrice) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب إدخال سعر واحد على الأقل (ساعة، يوم، شهر)',
                    'error_type' => 'price_required',
                    'status_code' => 400,
                    'available_options' => [
                        'price_per_hour',
                        'price_per_day',
                        'price_per_month'
                    ]
                ], 400);
            }

            // 3. بدء المعاملة (Transaction)
            DB::beginTransaction();

            try {
                // 4. إنشاء الموقف الجديد
                $parkingSpot = Parking_spot::create([
                    'user_id' => $user->id,
                    'title' => $request->title,
                    'address' => $request->address,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'capacity' => $request->capacity,
                    'price_per_hour' => $request->has('price_per_hour') ? $request->price_per_hour : null,
                    'price_per_day' => $request->has('price_per_day') ? $request->price_per_day : null,
                    'price_per_month' => $request->has('price_per_month') ? $request->price_per_month : null,
                    'is_available' => 1,
                    'time_from' => $request->time_from,
                    'time_to' => $request->time_to,
                    'cancellation_policy' => $request->cancellation_policy ?? 'لا يوجد سياسة إلغاء',
                    'is_active' => 0,
                    'work_days' => json_encode($request->work_days), // تحويل المصفوفة إلى JSON
                ]);

                // 5. رفع صور الموقف (عدد غير محدد)
                $uploadedImages = [];
                
                if ($request->hasFile('images')) {
                    $images = $request->file('images');
                    
                    if (!is_array($images)) {
                        $images = [$images];
                    }
                    
                    foreach ($images as $image) {
                        if ($image && $image->isValid()) {
                            $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                            $path = $image->storeAs('parking_spots', $imageName, 'public');
                            
                            $imageRecord = Image::create([
                                'path' => $path,
                                'title' => $request->title . '_' . time(),
                                'imageable_type' => 'App\Models\Parking_spot',
                                'imageable_id' => $parkingSpot->id,
                            ]);
                            
                            $uploadedImages[] = $imageRecord;
                        }
                    }
                }

                // 6. رفع سند الملكية
                $ownershipDocument = null;
                if ($request->hasFile('ownership_document')) {
                    $file = $request->file('ownership_document');
                    $fileName = time() . '_ownership_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs('documents/ownership', $fileName, 'public');
                    
                    $ownershipDocument = Image_documents::create([
                        'path' => $path,
                        'title' => 'سند ملكية - ' . $request->title,
                        'imageable_type' => 'App\Models\Parking_spot',
                        'imageable_id' => $parkingSpot->id,
                    ]);
                }

                // 7. رفع رخصة البلدية
                $municipalityLicense = null;
                if ($request->hasFile('municipality_license')) {
                    $file = $request->file('municipality_license');
                    $fileName = time() . '_license_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs('documents/license', $fileName, 'public');
                    
                    $municipalityLicense = Image_documents::create([
                        'path' => $path,
                        'title' => 'رخصة بلدية - ' . $request->title,
                        'imageable_type' => 'App\Models\Parking_spot',
                        'imageable_id' => $parkingSpot->id,
                    ]);
                }

                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            // 8. تحميل العلاقات
            $parkingSpot->load(['images', 'user']);

            // 9. تحديد الأسعار المدخلة
            $pricesAdded = [];
            if ($request->has('price_per_hour')) {
                $pricesAdded['price_per_hour'] = $request->price_per_hour;
            }
            if ($request->has('price_per_day')) {
                $pricesAdded['price_per_day'] = $request->price_per_day;
            }
            if ($request->has('price_per_month')) {
                $pricesAdded['price_per_month'] = $request->price_per_month;
            }

            // 10. تسجيل العملية
            Log::info('تم إضافة موقف جديد مع المستندات', [
                'user_id' => $user->id,
                'parking_spot_id' => $parkingSpot->id,
                'title' => $parkingSpot->title,
                'work_days' => $request->work_days,
                'prices_added' => $pricesAdded,
                'images_count' => count($uploadedImages),
                'ip' => $request->ip()
            ]);

            // 11. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => 'تم إضافة الموقف بنجاح، في انتظار موافقة الإدارة',
                'data' => [
                    'parking_spot' => $parkingSpot,
                    'images' => $uploadedImages,
                    'images_count' => count($uploadedImages),
                    'ownership_document' => $ownershipDocument,
                    'ownership_document_url' => $ownershipDocument ? asset('storage/' . $ownershipDocument->path) : null,
                    'municipality_license' => $municipalityLicense,
                    'municipality_license_url' => $municipalityLicense ? asset('storage/' . $municipalityLicense->path) : null,
                    'work_days' => $request->work_days,
                    'prices_added' => $pricesAdded,
                    'status' => [
                        'is_available' => 1,
                        'is_active' => 0,
                        'status_text' => 'قيد المراجعة'
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
            Log::error('خطأ في قاعدة البيانات أثناء إضافة الموقف', [
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
            Log::error('خطأ في إضافة الموقف', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إضافة الموقف',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }







    /////////////////////////////////////////////

 /**
 * عرض جميع مواقف صاحب الموقف الحالي فقط التي يكون فيها is_active = 1
 * مع عرض جميع المعلومات المدخلة لكل موقف (الصور، المستندات، الأسعار، أيام العمل)
 */
public function myParkingSpots(Request $request)
{
    try {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                'error_type' => 'unauthenticated',
                'status_code' => 401
            ], 401);
        }

        // جلب المواقف النشطة فقط (is_active = 1) مع جميع العلاقات
        $parkingSpots = Parking_spot::where('user_id', $user->id)
            ->where('is_active', 1)
            ->with([
                'images',
                'documents',
                'user'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        // تنسيق البيانات لكل موقف لعرض جميع المعلومات
        $formattedSpots = $parkingSpots->map(function ($spot) {
            // فصل المستندات
            $ownershipDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'سند ملكية') || 
                       str_contains($doc->title, 'ownership') ||
                       str_contains($doc->path, 'ownership');
            })->first();

            $licenseDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'رخصة بلدية') || 
                       str_contains($doc->title, 'license') ||
                       str_contains($doc->path, 'license');
            })->first();

            // تحديد الأسعار المدخلة
            $prices = [];
            if ($spot->price_per_hour !== null) {
                $prices['price_per_hour'] = $spot->price_per_hour;
            }
            if ($spot->price_per_day !== null) {
                $prices['price_per_day'] = $spot->price_per_day;
            }
            if ($spot->price_per_month !== null) {
                $prices['price_per_month'] = $spot->price_per_month;
            }

            return [
                // معلومات الموقف الأساسية
                'id' => $spot->id,
                'user_id' => $spot->user_id,
                'title' => $spot->title,
                'address' => $spot->address,
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                'capacity' => $spot->capacity,
                
                // الأسعار
                'price_per_hour' => $spot->price_per_hour,
                'price_per_day' => $spot->price_per_day,
                'price_per_month' => $spot->price_per_month,
                'prices_added' => $prices,
                
                // الحالة
                'is_available' => $spot->is_available,
                'is_active' => $spot->is_active,
                'status_text' => $spot->is_active == 1 ? 'مفعل' : 'غير مفعل',
                'availability_text' => $spot->is_available == 1 ? 'متاح' : 'غير متاح',
                
                // الأوقات
                'time_from' => $spot->time_from,
                'time_to' => $spot->time_to,
                
                // سياسة الإلغاء وأيام العمل
                'cancellation_policy' => $spot->cancellation_policy,
                'work_days' => $spot->work_days,
                
                // التواريخ
                'created_at' => $spot->created_at,
                'updated_at' => $spot->updated_at,
                
                // الصور
                'images' => $spot->images,
                'images_count' => $spot->images->count(),
                
                // المستندات
                'documents' => [
                    'ownership' => [
                        'id' => $ownershipDoc ? $ownershipDoc->id : null,
                        'path' => $ownershipDoc ? $ownershipDoc->path : null,
                        'title' => $ownershipDoc ? $ownershipDoc->title : null,
                        'url' => $ownershipDoc ? asset('storage/' . $ownershipDoc->path) : null,
                        'exists' => $ownershipDoc ? true : false,
                    ],
                    'municipality_license' => [
                        'id' => $licenseDoc ? $licenseDoc->id : null,
                        'path' => $licenseDoc ? $licenseDoc->path : null,
                        'title' => $licenseDoc ? $licenseDoc->title : null,
                        'url' => $licenseDoc ? asset('storage/' . $licenseDoc->path) : null,
                        'exists' => $licenseDoc ? true : false,
                    ]
                ],
                
                // صاحب الموقف
                'owner' => [
                    'id' => $spot->user->id ?? null,
                    'name' => $spot->user->name ?? null,
                    'phone' => $spot->user->phone ?? null,
                ]
            ];
        });

        // التحقق من وجود مواقف
        if ($formattedSpots->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'لا توجد مواقف نشطة',
                'data' => [],
                'count' => 0
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب المواقف النشطة بنجاح',
            'data' => $formattedSpots,
            'count' => $formattedSpots->count()
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب المواقف',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}

///////////////////////////
/**
 * عرض جميع مواقف صاحب الموقف الحالي فقط التي يكون فيها is_active = 0 (غير نشطة)
 */
public function myInactiveParkingSpots(Request $request)
{
    try {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                'error_type' => 'unauthenticated',
                'status_code' => 401
            ], 401);
        }

        // جلب المواقف غير النشطة فقط (is_active = 0)
        $parkingSpots = Parking_spot::where('user_id', $user->id)
            ->where('is_active', 0)
            ->with(['images', 'documents', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        // تنسيق البيانات لكل موقف لعرض جميع المعلومات
        $formattedSpots = $parkingSpots->map(function ($spot) {
            // فصل المستندات
            $ownershipDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'سند ملكية') || 
                       str_contains($doc->title, 'ownership') ||
                       str_contains($doc->path, 'ownership');
            })->first();

            $licenseDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'رخصة بلدية') || 
                       str_contains($doc->title, 'license') ||
                       str_contains($doc->path, 'license');
            })->first();

            // تحديد الأسعار المدخلة
            $prices = [];
            if ($spot->price_per_hour !== null) {
                $prices['price_per_hour'] = $spot->price_per_hour;
            }
            if ($spot->price_per_day !== null) {
                $prices['price_per_day'] = $spot->price_per_day;
            }
            if ($spot->price_per_month !== null) {
                $prices['price_per_month'] = $spot->price_per_month;
            }

            return [
                // معلومات الموقف الأساسية
                'id' => $spot->id,
                'user_id' => $spot->user_id,
                'title' => $spot->title,
                'address' => $spot->address,
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                'capacity' => $spot->capacity,
                
                // الأسعار
                'price_per_hour' => $spot->price_per_hour,
                'price_per_day' => $spot->price_per_day,
                'price_per_month' => $spot->price_per_month,
                'prices_added' => $prices,
                
                // الحالة
                'is_available' => $spot->is_available,
                'is_active' => $spot->is_active,
                'status_text' => 'غير مفعل',
                'availability_text' => $spot->is_available == 1 ? 'متاح' : 'غير متاح',
                
                // الأوقات
                'time_from' => $spot->time_from,
                'time_to' => $spot->time_to,
                
                // سياسة الإلغاء وأيام العمل
                'cancellation_policy' => $spot->cancellation_policy,
                'work_days' => $spot->work_days,
                
                // التواريخ
                'created_at' => $spot->created_at,
                'updated_at' => $spot->updated_at,
                
                // الصور
                'images' => $spot->images,
                'images_count' => $spot->images->count(),
                
                // المستندات
                'documents' => [
                    'ownership' => [
                        'id' => $ownershipDoc ? $ownershipDoc->id : null,
                        'path' => $ownershipDoc ? $ownershipDoc->path : null,
                        'title' => $ownershipDoc ? $ownershipDoc->title : null,
                        'url' => $ownershipDoc ? asset('storage/' . $ownershipDoc->path) : null,
                        'exists' => $ownershipDoc ? true : false,
                    ],
                    'municipality_license' => [
                        'id' => $licenseDoc ? $licenseDoc->id : null,
                        'path' => $licenseDoc ? $licenseDoc->path : null,
                        'title' => $licenseDoc ? $licenseDoc->title : null,
                        'url' => $licenseDoc ? asset('storage/' . $licenseDoc->path) : null,
                        'exists' => $licenseDoc ? true : false,
                    ]
                ],
                
                // صاحب الموقف
                'owner' => [
                    'id' => $spot->user->id ?? null,
                    'name' => $spot->user->name ?? null,
                    'phone' => $spot->user->phone ?? null,
                ]
            ];
        });

        // التحقق من وجود مواقف
        if ($formattedSpots->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'لا توجد مواقف غير نشطة',
                'data' => [],
                'count' => 0
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب المواقف غير النشطة بنجاح',
            'data' => $formattedSpots,
            'count' => $formattedSpots->count()
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب المواقف غير النشطة',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}






////////////////////

/**
 * عرض جميع المواقف فقط التي يكون فيها is_active = 0 (غير نشطة)
 * (للمدير فقط)
 */
public function getInactiveParkingSpots(Request $request)
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

        // 2. التحقق من أن المستخدم هو Admin (role = 1)
        $userRole = Role::where('user_id', $user->id)->first();
        
        if (!$userRole || $userRole->role != 1) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك، فقط المدير يمكنه عرض جميع المواقف غير النشطة',
                'error_type' => 'unauthorized',
                'status_code' => 403
            ], 403);
        }

        // 3. جلب جميع المواقف غير النشطة (is_active = 0)
        $parkingSpots = Parking_spot::where('is_active', 0)
            ->with(['images', 'documents', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        // 4. تنسيق البيانات لكل موقف
        $formattedSpots = $parkingSpots->map(function ($spot) {
            // فصل المستندات
            $ownershipDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'سند ملكية') || 
                       str_contains($doc->title, 'ownership') ||
                       str_contains($doc->path, 'ownership');
            })->first();

            $licenseDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'رخصة بلدية') || 
                       str_contains($doc->title, 'license') ||
                       str_contains($doc->path, 'license');
            })->first();

            // تحديد الأسعار المدخلة
            $prices = [];
            if ($spot->price_per_hour !== null) {
                $prices['price_per_hour'] = $spot->price_per_hour;
            }
            if ($spot->price_per_day !== null) {
                $prices['price_per_day'] = $spot->price_per_day;
            }
            if ($spot->price_per_month !== null) {
                $prices['price_per_month'] = $spot->price_per_month;
            }

            return [
                // معلومات الموقف الأساسية
                'id' => $spot->id,
                'user_id' => $spot->user_id,
                'title' => $spot->title,
                'address' => $spot->address,
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                'capacity' => $spot->capacity,
                
                // الأسعار
                'price_per_hour' => $spot->price_per_hour,
                'price_per_day' => $spot->price_per_day,
                'price_per_month' => $spot->price_per_month,
                'prices_added' => $prices,
                
                // الحالة
                'is_available' => $spot->is_available,
                'is_active' => $spot->is_active,
                'status_text' => 'غير مفعل',
                'availability_text' => $spot->is_available == 1 ? 'متاح' : 'غير متاح',
                
                // الأوقات
                'time_from' => $spot->time_from,
                'time_to' => $spot->time_to,
                
                // سياسة الإلغاء وأيام العمل
                'cancellation_policy' => $spot->cancellation_policy,
                'work_days' => $spot->work_days,
                
                // التواريخ
                'created_at' => $spot->created_at,
                'updated_at' => $spot->updated_at,
                
                // الصور
                'images' => $spot->images,
                'images_count' => $spot->images->count(),
                
                // المستندات
                'documents' => [
                    'ownership' => [
                        'id' => $ownershipDoc ? $ownershipDoc->id : null,
                        'path' => $ownershipDoc ? $ownershipDoc->path : null,
                        'title' => $ownershipDoc ? $ownershipDoc->title : null,
                        'url' => $ownershipDoc ? asset('storage/' . $ownershipDoc->path) : null,
                        'exists' => $ownershipDoc ? true : false,
                    ],
                    'municipality_license' => [
                        'id' => $licenseDoc ? $licenseDoc->id : null,
                        'path' => $licenseDoc ? $licenseDoc->path : null,
                        'title' => $licenseDoc ? $licenseDoc->title : null,
                        'url' => $licenseDoc ? asset('storage/' . $licenseDoc->path) : null,
                        'exists' => $licenseDoc ? true : false,
                    ]
                ],
                
                // صاحب الموقف
                'owner' => [
                    'id' => $spot->user->id ?? null,
                    'name' => $spot->user->name ?? null,
                    'phone' => $spot->user->phone ?? null,
                ]
            ];
        });

        // 5. التحقق من وجود مواقف
        if ($formattedSpots->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'لا توجد مواقف غير نشطة',
                'data' => [],
                'count' => 0
            ], 200);
        }

        // 6. تسجيل العملية
        Log::info('تم عرض جميع المواقف غير النشطة بواسطة المدير', [
            'admin_id' => $user->id,
            'admin_name' => $user->name,
            'count' => $formattedSpots->count(),
            'ip' => $request->ip()
        ]);

        // 7. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => 'تم جلب جميع المواقف غير النشطة بنجاح',
            'data' => $formattedSpots,
            'count' => $formattedSpots->count()
        ], 200);

    } catch (\Exception $e) {
        Log::error('خطأ في عرض المواقف غير النشطة', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب المواقف غير النشطة',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}


///////////////////////////
/**
 * عرض جميع المواقف النشطة (is_active = 1) لجميع المستخدمين
 * (يمكن لجميع المستخدمين المسجلين عرضها)
 */
public function getAllActiveParkingSpots(Request $request)
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

        // 2. جلب جميع المواقف النشطة (is_active = 1) والمتاحة (is_available = 1)
        $parkingSpots = Parking_spot::where('is_active', 1)
            ->where('is_available', 1)
            ->with(['images', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        // 3. تنسيق البيانات لكل موقف
        $formattedSpots = $parkingSpots->map(function ($spot) {
            // تحديد الأسعار المدخلة
            $prices = [];
            if ($spot->price_per_hour !== null) {
                $prices['price_per_hour'] = $spot->price_per_hour;
            }
            if ($spot->price_per_day !== null) {
                $prices['price_per_day'] = $spot->price_per_day;
            }
            if ($spot->price_per_month !== null) {
                $prices['price_per_month'] = $spot->price_per_month;
            }

            // حساب أقل سعر
            $lowestPrice = !empty($prices) ? min($prices) : null;

            return [
                // معلومات الموقف الأساسية
                'id' => $spot->id,
                'user_id' => $spot->user_id,
                'title' => $spot->title,
                'address' => $spot->address,
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                'capacity' => $spot->capacity,
                
                // الأسعار
                'price_per_hour' => $spot->price_per_hour,
                'price_per_day' => $spot->price_per_day,
                'price_per_month' => $spot->price_per_month,
                'prices_added' => $prices,
                'lowest_price' => $lowestPrice,
                
                // الحالة
                'is_available' => $spot->is_available,
                'is_active' => $spot->is_active,
                'status_text' => 'مفعل',
                'availability_text' => 'متاح',
                
                // الأوقات
                'time_from' => $spot->time_from,
                'time_to' => $spot->time_to,
                
                // أيام العمل
                'work_days' => $spot->work_days,
                
                // التواريخ
                'created_at' => $spot->created_at,
                'updated_at' => $spot->updated_at,
                
                // الصور
                'images' => $spot->images,
                'images_count' => $spot->images->count(),
                
                // صاحب الموقف
                'owner' => [
                    'id' => $spot->user->id ?? null,
                    'name' => $spot->user->name ?? null,
                    'phone' => $spot->user->phone ?? null,
                ]
            ];
        });

        // 4. التحقق من وجود مواقف
        if ($formattedSpots->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'لا توجد مواقف نشطة متاحة حالياً',
                'data' => [],
                'count' => 0
            ], 200);
        }

        // 5. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => 'تم جلب جميع المواقف النشطة بنجاح',
            'data' => $formattedSpots,
            'count' => $formattedSpots->count()
        ], 200);

    } catch (\Exception $e) {
        Log::error('خطأ في عرض المواقف النشطة', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب المواقف النشطة',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}

/////////////////////////////




/**
 * عرض المواقف النشطة والمتاحة مرتبة حسب الأرخص بناءً على price_type
 * is_active = 1 و is_available = 1 فقط
 */
public function getParkingSpotsByPrice(Request $request)
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

        // 2. التحقق من وجود price_type في الطلب
        if (!$request->has('price_type')) {
            return response()->json([
                'success' => false,
                'message' => 'يجب تحديد نوع السعر (price_type)',
                'error_type' => 'price_type_required',
                'status_code' => 400,
                'available_options' => [
                    'price_type' => ['hour', 'day', 'month']
                ]
            ], 400);
        }

        // 3. الحصول على نوع السعر من الرابط
        $priceType = $request->get('price_type');

        // 4. التحقق من صحة price_type
        $allowedTypes = ['hour', 'day', 'month'];
        if (!in_array($priceType, $allowedTypes)) {
            return response()->json([
                'success' => false,
                'message' => 'نوع السعر غير صحيح',
                'error_type' => 'invalid_price_type',
                'status_code' => 400,
                'available_options' => [
                    'price_type' => ['hour', 'day', 'month']
                ]
            ], 400);
        }

        // 5. تحديد العمود المناسب للترتيب
        $priceColumn = match($priceType) {
            'day' => 'price_per_day',
            'month' => 'price_per_month',
            default => 'price_per_hour'
        };

        // 6. جلب المواقف النشطة والمتاحة (is_active = 1 و is_available = 1)
        $parkingSpots = Parking_spot::where('is_active', 1)
            ->where('is_available', 1)  // إضافة شرط is_available = 1
            ->whereNotNull($priceColumn)
            ->with(['images', 'user'])
            ->orderBy($priceColumn, 'asc')
            ->get();

        // 7. تنسيق البيانات لكل موقف
        $formattedSpots = $parkingSpots->map(function ($spot) use ($priceColumn, $priceType) {
            // تحديد الأسعار المدخلة
            $prices = [];
            if ($spot->price_per_hour !== null) {
                $prices['price_per_hour'] = $spot->price_per_hour;
            }
            if ($spot->price_per_day !== null) {
                $prices['price_per_day'] = $spot->price_per_day;
            }
            if ($spot->price_per_month !== null) {
                $prices['price_per_month'] = $spot->price_per_month;
            }

            $lowestPrice = !empty($prices) ? min($prices) : null;
            
            // تحديد نوع السعر المستخدم للترتيب
            $priceTypeText = match($priceType) {
                'day' => 'سعر اليوم',
                'month' => 'سعر الشهر',
                default => 'سعر الساعة'
            };

            return [
                // معلومات الموقف الأساسية
                'id' => $spot->id,
                'user_id' => $spot->user_id,
                'title' => $spot->title,
                'address' => $spot->address,
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                'capacity' => $spot->capacity,
                
                // الأسعار
                'price_per_hour' => $spot->price_per_hour,
                'price_per_day' => $spot->price_per_day,
                'price_per_month' => $spot->price_per_month,
                'prices_added' => $prices,
                'lowest_price' => $lowestPrice,
                
                // السعر المستخدم للترتيب
                'sort_price' => $spot->{$priceColumn},
                'price_type_used' => $priceTypeText,
                
                // الحالة
                'is_available' => $spot->is_available,
                'is_active' => $spot->is_active,
                'status_text' => 'مفعل ومتاح',
                'availability_text' => 'متاح',
                
                // الأوقات
                'time_from' => $spot->time_from,
                'time_to' => $spot->time_to,
                
                // سياسة الإلغاء وأيام العمل
                'cancellation_policy' => $spot->cancellation_policy,
                'work_days' => $spot->work_days,
                
                // التواريخ
                'created_at' => $spot->created_at,
                'updated_at' => $spot->updated_at,
                
                // الصور
                'images' => $spot->images,
                'images_count' => $spot->images->count(),
                
                // صاحب الموقف
                'owner' => [
                    'id' => $spot->user->id ?? null,
                    'name' => $spot->user->name ?? null,
                    'phone' => $spot->user->phone ?? null,
                ]
            ];
        });

        // 8. التحقق من وجود مواقف
        if ($formattedSpots->isEmpty()) {
            $typeName = match($priceType) {
                'day' => 'ليوم',
                'month' => 'لشهر',
                default => 'لساعة'
            };
            
            return response()->json([
                'success' => true,
                'message' => "لا توجد مواقف نشطة ومتاحة حسب السعر {$typeName}",
                'data' => [],
                'count' => 0
            ], 200);
        }

        // 9. إرجاع الاستجابة
        $typeName = match($priceType) {
            'day' => 'اليوم',
            'month' => 'الشهر',
            default => 'الساعة'
        };

        return response()->json([
            'success' => true,
            'message' => "تم جلب المواقف النشطة والمتاحة مرتبة حسب سعر {$typeName} (الأرخص أولاً)",
            'data' => $formattedSpots,
            'count' => $formattedSpots->count(),
            'meta' => [
                'price_type' => $priceType,
                'price_column' => $priceColumn,
                'sort_order' => 'asc (الأرخص أولاً)',
                'filters' => [
                    'is_active' => 1,
                    'is_available' => 1,
                ]
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('خطأ في عرض المواقف مرتبة حسب السعر', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب المواقف',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}











/////////////////////////////

/**
 * تغيير حالة التفعيل للموقف (is_active)
 * 1 = مفعل, 0 = غير مفعل
 * فقط المدير (Admin) يمكنه تغيير الحالة
 */
public function toggleActive(Request $request, $id)
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

        // 2. التحقق من أن المستخدم هو Admin فقط (role = 1)
        $userRole = Role::where('user_id', $user->id)->first();
        
        if (!$userRole || $userRole->role != 1) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك، فقط المدير يمكنه تغيير حالة التفعيل',
                'error_type' => 'unauthorized',
                'status_code' => 403
            ], 403);
        }

        // 3. البحث عن الموقف
        $parkingSpot = Parking_spot::find($id);
        
        if (!$parkingSpot) {
            return response()->json([
                'success' => false,
                'message' => 'الموقف غير موجود',
                'error_type' => 'not_found',
                'status_code' => 404
            ], 404);
        }

        // 4. التحقق من صحة البيانات
        $request->validate([
            'is_active' => 'required|boolean',
        ], [
            'is_active.required' => 'حالة التفعيل مطلوبة',
            'is_active.boolean' => 'حالة التفعيل يجب أن تكون true أو false',
        ]);

        // 5. تغيير حالة التفعيل
        $oldStatus = $parkingSpot->is_active;
        $parkingSpot->is_active = $request->is_active;
        $parkingSpot->save();

        // 6. تسجيل العملية
        Log::info('تم تغيير حالة تفعيل الموقف بواسطة المدير', [
            'admin_id' => $user->id,
            'admin_name' => $user->name,
            'parking_spot_id' => $parkingSpot->id,
            'title' => $parkingSpot->title,
            'owner_id' => $parkingSpot->user_id,
            'old_status' => $oldStatus,
            'new_status' => $request->is_active,
            'ip' => $request->ip()
        ]);

        // 7. تحديد الرسالة المناسبة
        $statusMessage = $request->is_active == 1 ? 'مفعل' : 'غير مفعل';

        // 8. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => "تم تغيير حالة التفعيل إلى {$statusMessage} بنجاح بواسطة المدير",
            'data' => [
                'parking_spot' => [
                    'id' => $parkingSpot->id,
                    'title' => $parkingSpot->title,
                    'is_active' => $parkingSpot->is_active,
                    'status' => $statusMessage,
                ],
                'old_status' => $oldStatus,
                'new_status' => $request->is_active,
                'updated_by' => [
                    'admin_id' => $user->id,
                    'admin_name' => $user->name,
                ],
                'updated_at' => $parkingSpot->updated_at
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
        Log::error('خطأ في قاعدة البيانات أثناء تغيير حالة التفعيل', [
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
        Log::error('خطأ في تغيير حالة التفعيل', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء تغيير حالة التفعيل',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}












///////////////////////////
/**
 * عرض تفاصيل موقف معين
 */
public function show($id)
{
    try {
        // 1. التحقق من أن المستخدم مسجل دخول
        $user = request()->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                'error_type' => 'unauthenticated',
                'status_code' => 401
            ], 401);
        }

        // 2. البحث عن الموقف مع جميع العلاقات
        $parkingSpot = Parking_spot::with([
            'images',
            'documents',
            'user',
            'bookings' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(5);
            }
        ])->find($id);
        
        if (!$parkingSpot) {
            return response()->json([
                'success' => false,
                'message' => 'الموقف غير موجود',
                'error_type' => 'not_found',
                'status_code' => 404
            ], 404);
        }

        // 3. التحقق من الصلاحية
        $userRole = Role::where('user_id', $user->id)->first();
        $isOwner = $parkingSpot->user_id == $user->id;
        $isAdmin = $userRole && $userRole->role == 1;

        // إذا كان الموقف غير نشط، فقط المالك أو المدير يمكنه المشاهدة
        if ($parkingSpot->is_active == 0 && !$isOwner && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الموقف غير متاح حالياً',
                'error_type' => 'not_available',
                'status_code' => 403
            ], 403);
        }

        // 4. فصل المستندات
        $ownershipDoc = $parkingSpot->documents->filter(function ($doc) {
            return str_contains($doc->title, 'سند ملكية') || 
                   str_contains($doc->title, 'ownership') ||
                   str_contains($doc->path, 'ownership');
        })->first();

        $licenseDoc = $parkingSpot->documents->filter(function ($doc) {
            return str_contains($doc->title, 'رخصة بلدية') || 
                   str_contains($doc->title, 'license') ||
                   str_contains($doc->path, 'license');
        })->first();

        // 5. تحديد الأسعار المدخلة
        $prices = [];
        if ($parkingSpot->price_per_hour !== null) {
            $prices['price_per_hour'] = $parkingSpot->price_per_hour;
        }
        if ($parkingSpot->price_per_day !== null) {
            $prices['price_per_day'] = $parkingSpot->price_per_day;
        }
        if ($parkingSpot->price_per_month !== null) {
            $prices['price_per_month'] = $parkingSpot->price_per_month;
        }

        $lowestPrice = !empty($prices) ? min($prices) : null;

        // 6. تنسيق البيانات
        $data = [
            // معلومات الموقف الأساسية
            'id' => $parkingSpot->id,
            'user_id' => $parkingSpot->user_id,
            'title' => $parkingSpot->title,
            'address' => $parkingSpot->address,
            'latitude' => $parkingSpot->latitude,
            'longitude' => $parkingSpot->longitude,
            'capacity' => $parkingSpot->capacity,
            
            // الأسعار
            'price_per_hour' => $parkingSpot->price_per_hour,
            'price_per_day' => $parkingSpot->price_per_day,
            'price_per_month' => $parkingSpot->price_per_month,
            'prices_added' => $prices,
            'lowest_price' => $lowestPrice,
            
            // الحالة
            'is_available' => $parkingSpot->is_available,
            'is_active' => $parkingSpot->is_active,
            'status_text' => $parkingSpot->is_active == 1 ? 'مفعل' : 'غير مفعل',
            'availability_text' => $parkingSpot->is_available == 1 ? 'متاح' : 'غير متاح',
            
            // الأوقات
            'time_from' => $parkingSpot->time_from,
            'time_to' => $parkingSpot->time_to,
            
            // سياسة الإلغاء وأيام العمل
            'cancellation_policy' => $parkingSpot->cancellation_policy,
            'work_days' => $parkingSpot->work_days,
            
            // التواريخ
            'created_at' => $parkingSpot->created_at,
            'updated_at' => $parkingSpot->updated_at,
            
            // الصور
            'images' => $parkingSpot->images,
            'images_count' => $parkingSpot->images->count(),
            
            // المستندات (تظهر فقط للمالك أو المدير)
            'documents' => ($isOwner || $isAdmin) ? [
                'ownership' => [
                    'id' => $ownershipDoc ? $ownershipDoc->id : null,
                    'path' => $ownershipDoc ? $ownershipDoc->path : null,
                    'title' => $ownershipDoc ? $ownershipDoc->title : null,
                    'url' => $ownershipDoc ? asset('storage/' . $ownershipDoc->path) : null,
                    'exists' => $ownershipDoc ? true : false,
                ],
                'municipality_license' => [
                    'id' => $licenseDoc ? $licenseDoc->id : null,
                    'path' => $licenseDoc ? $licenseDoc->path : null,
                    'title' => $licenseDoc ? $licenseDoc->title : null,
                    'url' => $licenseDoc ? asset('storage/' . $licenseDoc->path) : null,
                    'exists' => $licenseDoc ? true : false,
                ]
            ] : null,
            
            // صاحب الموقف
            'owner' => [
                'id' => $parkingSpot->user->id ?? null,
                'name' => $parkingSpot->user->name ?? null,
                'phone' => $parkingSpot->user->phone ?? null,
            ],
            
            // الحجوزات الأخيرة (تظهر فقط للمالك أو المدير)
            'recent_bookings' => ($isOwner || $isAdmin) ? $parkingSpot->bookings : null,
            'bookings_count' => $parkingSpot->bookings->count(),
        ];

        // 7. تسجيل العملية
        Log::info('تم عرض تفاصيل الموقف', [
            'user_id' => $user->id,
            'parking_spot_id' => $parkingSpot->id,
            'title' => $parkingSpot->title,
            'ip' => request()->ip()
        ]);

        // 8. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل الموقف بنجاح',
            'data' => $data
        ], 200);

    } catch (\Exception $e) {
        Log::error('خطأ في عرض تفاصيل الموقف', [
            'error' => $e->getMessage(),
            'parking_spot_id' => $id ?? null
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء عرض تفاصيل الموقف',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}

///////////////////


/**
 * عرض تفاصيل صاحب الموقف (المستخدم) الذي يملك موقفاً معيناً
 */
public function getParkingOwnerDetails($parkingSpotId)
{
    try {
        // 1. التحقق من أن المستخدم مسجل دخول
        $user = request()->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                'error_type' => 'unauthenticated',
                'status_code' => 401
            ], 401);
        }

        // 2. البحث عن الموقف
        $parkingSpot = Parking_spot::with(['user', 'user.role', 'user.images'])
            ->find($parkingSpotId);
        
        if (!$parkingSpot) {
            return response()->json([
                'success' => false,
                'message' => 'الموقف غير موجود',
                'error_type' => 'not_found',
                'status_code' => 404
            ], 404);
        }

        // 3. التحقق من أن الموقف نشط (أو أن المستخدم هو المالك أو مدير)
        $currentUser = $user;
        $userRole = Role::where('user_id', $currentUser->id)->first();
        $isOwner = $parkingSpot->user_id == $currentUser->id;
        $isAdmin = $userRole && $userRole->role == 1;

        // إذا كان الموقف غير نشط، فقط المالك أو المدير يمكنه مشاهدة تفاصيل المالك
        if ($parkingSpot->is_active == 0 && !$isOwner && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الموقف غير متاح حالياً',
                'error_type' => 'not_available',
                'status_code' => 403
            ], 403);
        }

        // 4. جلب بيانات صاحب الموقف
        $owner = $parkingSpot->user;
        
        if (!$owner) {
            return response()->json([
                'success' => false,
                'message' => 'صاحب الموقف غير موجود',
                'error_type' => 'owner_not_found',
                'status_code' => 404
            ], 404);
        }

        // 5. جلب صورة المستخدم الشخصية
        $profileImage = $owner->images->first();

        // 6. جلب دور المستخدم
        $role = $owner->role;

        // 7. جلب إحصائيات صاحب الموقف
        $totalParkingSpots = Parking_spot::where('user_id', $owner->id)->count();
        $activeParkingSpots = Parking_spot::where('user_id', $owner->id)
            ->where('is_active', 1)
            ->count();
        $inactiveParkingSpots = Parking_spot::where('user_id', $owner->id)
            ->where('is_active', 0)
            ->count();

        // 8. تنسيق البيانات
        $data = [
            // معلومات الموقف
            'parking_spot' => [
                'id' => $parkingSpot->id,
                'title' => $parkingSpot->title,
                'address' => $parkingSpot->address,
                'is_active' => $parkingSpot->is_active,
                'is_available' => $parkingSpot->is_available,
                'status_text' => $parkingSpot->is_active == 1 ? 'مفعل' : 'غير مفعل',
            ],
            
            // معلومات صاحب الموقف
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'phone' => $owner->phone,
                'language' => $owner->language,
                'created_at' => $owner->created_at,
                'updated_at' => $owner->updated_at,
                
                // صورة المالك
                'profile_image' => $profileImage ? [
                    'id' => $profileImage->id,
                    'path' => $profileImage->path,
                    'url' => asset('storage/' . $profileImage->path),
                ] : null,
                
                // دور المالك
                'role' => $role ? [
                    'id' => $role->id,
                    'role' => $role->role,
                    'role_name' => $role->role == 1 ? 'مدير' : ($role->role == 2 ? 'صاحب موقف' : 'مستأجر'),
                ] : null,
                
                // إحصائيات
                'statistics' => [
                    'total_parking_spots' => $totalParkingSpots,
                    'active_parking_spots' => $activeParkingSpots,
                    'inactive_parking_spots' => $inactiveParkingSpots,
                ]
            ]
        ];

        // 9. إضافة معلومات إضافية للمالك أو المدير
        if ($isOwner || $isAdmin) {
            // يمكن إضافة معلومات إضافية مثل البريد الإلكتروني أو معلومات حساسة أخرى
            $data['owner']['email'] = $owner->email ?? null;
            $data['owner']['is_blocked'] = $owner->is_blocked ?? false;
        }

        // 10. تسجيل العملية
        Log::info('تم عرض تفاصيل صاحب الموقف', [
            'user_id' => $currentUser->id,
            'parking_spot_id' => $parkingSpotId,
            'owner_id' => $owner->id,
            'ip' => request()->ip()
        ]);

        // 11. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل صاحب الموقف بنجاح',
            'data' => $data
        ], 200);

    } catch (\Exception $e) {
        Log::error('خطأ في عرض تفاصيل صاحب الموقف', [
            'error' => $e->getMessage(),
            'parking_spot_id' => $parkingSpotId ?? null
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء عرض تفاصيل صاحب الموقف',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}


//////////////////////////////////


/**
 * عرض المواقف المتاحة والنشطة مع المستندات
 * is_available = 1 و is_active = 1
 */
public function getAvailableParkingSpots(Request $request)
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

        // 2. بناء الاستعلام (is_active = 1 و is_available = 1)
        $query = Parking_spot::where('is_active', 1)
            ->where('is_available', 1)
            ->with(['images', 'documents', 'user']);

        // 3. فلترة حسب المدينة
        if ($request->has('city') && $request->city) {
            $query->where('address', 'LIKE', "%{$request->city}%");
        }

        // 4. فلترة حسب السعة
        if ($request->has('capacity') && $request->capacity) {
            $query->where('capacity', '>=', $request->capacity);
        }

        // 5. فلترة حسب السعر (الحد الأدنى)
        if ($request->has('min_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('price_per_hour', '>=', $request->min_price)
                  ->orWhere('price_per_day', '>=', $request->min_price)
                  ->orWhere('price_per_month', '>=', $request->min_price);
            });
        }

        // 6. فلترة حسب السعر (الحد الأقصى)
        if ($request->has('max_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('price_per_hour', '<=', $request->max_price)
                  ->orWhere('price_per_day', '<=', $request->max_price)
                  ->orWhere('price_per_month', '<=', $request->max_price);
            });
        }

        // 7. البحث بالعنوان
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('address', 'LIKE', "%{$search}%");
            });
        }

        // 8. فلترة حسب أيام العمل
        if ($request->has('work_days') && $request->work_days) {
            $workDays = $request->work_days;
            if (is_array($workDays)) {
                $query->where(function ($q) use ($workDays) {
                    foreach ($workDays as $day) {
                        $q->orWhere('work_days', 'LIKE', "%\"{$day}\"%");
                    }
                });
            } else {
                $query->where('work_days', 'LIKE', "%\"{$request->work_days}\"%");
            }
        }

        // 9. ترتيب النتائج
        $sortField = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSortFields = ['id', 'title', 'price_per_hour', 'capacity', 'created_at', 'updated_at'];
        
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortOrder);
        }

        // 10. عدد النتائج في الصفحة
        $perPage = $request->get('per_page', 15);
        if ($perPage > 100) {
            $perPage = 100;
        }

        // 11. جلب النتائج
        $parkingSpots = $query->paginate($perPage);

        // 12. تنسيق البيانات مع المستندات
        $formattedSpots = $parkingSpots->getCollection()->map(function ($spot) {
            // فصل المستندات
            $ownershipDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'سند ملكية') || 
                       str_contains($doc->title, 'ownership') ||
                       str_contains($doc->path, 'ownership');
            })->first();

            $licenseDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'رخصة بلدية') || 
                       str_contains($doc->title, 'license') ||
                       str_contains($doc->path, 'license');
            })->first();

            // تحديد الأسعار المدخلة
            $prices = [];
            if ($spot->price_per_hour !== null) {
                $prices['price_per_hour'] = $spot->price_per_hour;
            }
            if ($spot->price_per_day !== null) {
                $prices['price_per_day'] = $spot->price_per_day;
            }
            if ($spot->price_per_month !== null) {
                $prices['price_per_month'] = $spot->price_per_month;
            }

            $lowestPrice = !empty($prices) ? min($prices) : null;

            return [
                // معلومات الموقف الأساسية
                'id' => $spot->id,
                'user_id' => $spot->user_id,
                'title' => $spot->title,
                'address' => $spot->address,
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                'capacity' => $spot->capacity,
                
                // الأسعار
                'price_per_hour' => $spot->price_per_hour,
                'price_per_day' => $spot->price_per_day,
                'price_per_month' => $spot->price_per_month,
                'prices_added' => $prices,
                'lowest_price' => $lowestPrice,
                
                // الحالة
                'is_available' => $spot->is_available,
                'is_active' => $spot->is_active,
                'status_text' => 'متاح ومفعل',
                'availability_text' => 'متاح',
                
                // الأوقات
                'time_from' => $spot->time_from,
                'time_to' => $spot->time_to,
                
                // سياسة الإلغاء وأيام العمل
                'cancellation_policy' => $spot->cancellation_policy,
                'work_days' => $spot->work_days,
                
                // التواريخ
                'created_at' => $spot->created_at,
                'updated_at' => $spot->updated_at,
                
                // الصور
                'images' => $spot->images,
                'images_count' => $spot->images->count(),
                
                // المستندات (سند الملكية ورخصة البلدية)
                'documents' => [
                    'ownership' => [
                        'id' => $ownershipDoc ? $ownershipDoc->id : null,
                        'path' => $ownershipDoc ? $ownershipDoc->path : null,
                        'title' => $ownershipDoc ? $ownershipDoc->title : null,
                        'url' => $ownershipDoc ? asset('storage/' . $ownershipDoc->path) : null,
                        'exists' => $ownershipDoc ? true : false,
                    ],
                    'municipality_license' => [
                        'id' => $licenseDoc ? $licenseDoc->id : null,
                        'path' => $licenseDoc ? $licenseDoc->path : null,
                        'title' => $licenseDoc ? $licenseDoc->title : null,
                        'url' => $licenseDoc ? asset('storage/' . $licenseDoc->path) : null,
                        'exists' => $licenseDoc ? true : false,
                    ]
                ],
                
                // صاحب الموقف
                'owner' => [
                    'id' => $spot->user->id ?? null,
                    'name' => $spot->user->name ?? null,
                    'phone' => $spot->user->phone ?? null,
                ]
            ];
        });

        $parkingSpots->setCollection($formattedSpots);

        // 13. التحقق من وجود مواقف
        if ($parkingSpots->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'لا توجد مواقف متاحة ونشطة حالياً',
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'per_page' => $perPage,
                    'current_page' => 1,
                    'last_page' => 1
                ]
            ], 200);
        }

        // 14. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => 'تم جلب المواقف المتاحة والنشطة بنجاح',
            'data' => $parkingSpots,
            'meta' => [
                'total' => $parkingSpots->total(),
                'per_page' => $parkingSpots->perPage(),
                'current_page' => $parkingSpots->currentPage(),
                'last_page' => $parkingSpots->lastPage(),
                'filters' => [
                    'city' => $request->city,
                    'capacity' => $request->capacity,
                    'min_price' => $request->min_price,
                    'max_price' => $request->max_price,
                    'search' => $request->search,
                    'work_days' => $request->work_days,
                    'sort_by' => $sortField,
                    'sort_order' => $sortOrder,
                ]
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('خطأ في عرض المواقف المتاحة والنشطة', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب المواقف المتاحة والنشطة',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}


////////////////////////

/**
 * عرض المواقف غير المتاحة والنشطة مع المستندات
 * is_available = 0 و is_active = 1
 */
public function getUnavailableParkingSpots(Request $request)
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

        // 2. التحقق من صلاحيات المستخدم (فقط المالك أو المدير)
        $userRole = Role::where('user_id', $user->id)->first();
        $isAdmin = $userRole && $userRole->role == 1;

        // 3. بناء الاستعلام (is_active = 1 و is_available = 0)
        $query = Parking_spot::where('is_active', 1)
            ->where('is_available', 0)
            ->with(['images', 'documents', 'user']);

        // 4. إذا كان المستخدم ليس مديراً، يعرض فقط مواقفه
        if (!$isAdmin) {
            $query->where('user_id', $user->id);
        }

        // 5. فلترة حسب المدينة
        if ($request->has('city') && $request->city) {
            $query->where('address', 'LIKE', "%{$request->city}%");
        }

        // 6. فلترة حسب السعة
        if ($request->has('capacity') && $request->capacity) {
            $query->where('capacity', '>=', $request->capacity);
        }

        // 7. البحث بالعنوان
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('address', 'LIKE', "%{$search}%");
            });
        }

        // 8. فلترة حسب صاحب الموقف (للمدير فقط)
        if ($isAdmin && $request->has('owner_id') && $request->owner_id) {
            $query->where('user_id', $request->owner_id);
        }

        // 9. ترتيب النتائج
        $sortField = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSortFields = ['id', 'title', 'price_per_hour', 'capacity', 'created_at', 'updated_at'];
        
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortOrder);
        }

        // 10. عدد النتائج في الصفحة
        $perPage = $request->get('per_page', 15);
        if ($perPage > 100) {
            $perPage = 100;
        }

        // 11. جلب النتائج
        $parkingSpots = $query->paginate($perPage);

        // 12. تنسيق البيانات مع المستندات
        $formattedSpots = $parkingSpots->getCollection()->map(function ($spot) {
            // فصل المستندات
            $ownershipDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'سند ملكية') || 
                       str_contains($doc->title, 'ownership') ||
                       str_contains($doc->path, 'ownership');
            })->first();

            $licenseDoc = $spot->documents->filter(function ($doc) {
                return str_contains($doc->title, 'رخصة بلدية') || 
                       str_contains($doc->title, 'license') ||
                       str_contains($doc->path, 'license');
            })->first();

            // تحديد الأسعار المدخلة
            $prices = [];
            if ($spot->price_per_hour !== null) {
                $prices['price_per_hour'] = $spot->price_per_hour;
            }
            if ($spot->price_per_day !== null) {
                $prices['price_per_day'] = $spot->price_per_day;
            }
            if ($spot->price_per_month !== null) {
                $prices['price_per_month'] = $spot->price_per_month;
            }

            $lowestPrice = !empty($prices) ? min($prices) : null;

            return [
                // معلومات الموقف الأساسية
                'id' => $spot->id,
                'user_id' => $spot->user_id,
                'title' => $spot->title,
                'address' => $spot->address,
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                'capacity' => $spot->capacity,
                
                // الأسعار
                'price_per_hour' => $spot->price_per_hour,
                'price_per_day' => $spot->price_per_day,
                'price_per_month' => $spot->price_per_month,
                'prices_added' => $prices,
                'lowest_price' => $lowestPrice,
                
                // الحالة
                'is_available' => $spot->is_available,
                'is_active' => $spot->is_active,
                'status_text' => 'غير متاح ومفعل',
                'availability_text' => 'غير متاح',
                'reason' => $spot->reason ?? 'الموقف غير متاح حالياً',
                
                // الأوقات
                'time_from' => $spot->time_from,
                'time_to' => $spot->time_to,
                
                // سياسة الإلغاء وأيام العمل
                'cancellation_policy' => $spot->cancellation_policy,
                'work_days' => $spot->work_days,
                
                // التواريخ
                'created_at' => $spot->created_at,
                'updated_at' => $spot->updated_at,
                
                // الصور
                'images' => $spot->images,
                'images_count' => $spot->images->count(),
                
                // المستندات (سند الملكية ورخصة البلدية)
                'documents' => [
                    'ownership' => [
                        'id' => $ownershipDoc ? $ownershipDoc->id : null,
                        'path' => $ownershipDoc ? $ownershipDoc->path : null,
                        'title' => $ownershipDoc ? $ownershipDoc->title : null,
                        'url' => $ownershipDoc ? asset('storage/' . $ownershipDoc->path) : null,
                        'exists' => $ownershipDoc ? true : false,
                    ],
                    'municipality_license' => [
                        'id' => $licenseDoc ? $licenseDoc->id : null,
                        'path' => $licenseDoc ? $licenseDoc->path : null,
                        'title' => $licenseDoc ? $licenseDoc->title : null,
                        'url' => $licenseDoc ? asset('storage/' . $licenseDoc->path) : null,
                        'exists' => $licenseDoc ? true : false,
                    ]
                ],
                
                // صاحب الموقف
                'owner' => [
                    'id' => $spot->user->id ?? null,
                    'name' => $spot->user->name ?? null,
                    'phone' => $spot->user->phone ?? null,
                ]
            ];
        });

        $parkingSpots->setCollection($formattedSpots);

        // 13. التحقق من وجود مواقف
        if ($parkingSpots->isEmpty()) {
            $message = $isAdmin ? 'لا توجد مواقف غير متاحة ونشطة' : 'لا توجد مواقف غير متاحة ونشطة خاصة بك';
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'per_page' => $perPage,
                    'current_page' => 1,
                    'last_page' => 1
                ]
            ], 200);
        }

        // 14. إرجاع الاستجابة
        $message = $isAdmin ? 'تم جلب المواقف غير المتاحة والنشطة بنجاح' : 'تم جلب مواقفك غير المتاحة والنشطة بنجاح';
        
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $parkingSpots,
            'meta' => [
                'total' => $parkingSpots->total(),
                'per_page' => $parkingSpots->perPage(),
                'current_page' => $parkingSpots->currentPage(),
                'last_page' => $parkingSpots->lastPage(),
                'filters' => [
                    'city' => $request->city,
                    'capacity' => $request->capacity,
                    'search' => $request->search,
                    'owner_id' => $request->owner_id,
                    'sort_by' => $sortField,
                    'sort_order' => $sortOrder,
                ]
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('خطأ في عرض المواقف غير المتاحة والنشطة', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب المواقف غير المتاحة والنشطة',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}



////////////////



























/**
 * عرض المواقف حسب الأقرب (بناءً على إحداثيات المستخدم)
 */
public function getNearestParkingSpots(Request $request)
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

        // 2. التحقق من وجود إحداثيات
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:1|max:50', // نصف القطر بالكيلومتر (اختياري)
        ], [
            'latitude.required' => 'خط العرض مطلوب',
            'latitude.numeric' => 'خط العرض يجب أن يكون رقماً',
            'latitude.between' => 'خط العرض يجب أن يكون بين -90 و 90',
            
            'longitude.required' => 'خط الطول مطلوب',
            'longitude.numeric' => 'خط الطول يجب أن يكون رقماً',
            'longitude.between' => 'خط الطول يجب أن يكون بين -180 و 180',
            
            'radius.numeric' => 'نصف القطر يجب أن يكون رقماً',
            'radius.min' => 'نصف القطر يجب أن يكون 1 كيلومتر على الأقل',
            'radius.max' => 'نصف القطر يجب أن لا يتجاوز 50 كيلومتر',
        ]);

        // 3. إعداد المتغيرات
        $latitude = $request->latitude;
        $longitude = $request->longitude;
        $radius = $request->radius ?? 10; // افتراضي 10 كيلومتر

        // 4. بناء الاستعلام (مع حساب المسافة)
        $query = Parking_spot::where('is_active', 1)
            ->where('is_available', 1)
            ->selectRaw("
                *,
                (
                    6371 * acos(
                        cos(radians(?)) * cos(radians(latitude)) *
                        cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    )
                ) AS distance
            ", [$latitude, $longitude, $latitude])
            ->having('distance', '<=', $radius) // تصفية حسب نصف القطر
            ->orderBy('distance', 'asc'); // ترتيب حسب الأقرب

        // 5. فلترة حسب السعر (اختياري)
        if ($request->has('min_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('price_per_hour', '>=', $request->min_price)
                  ->orWhere('price_per_day', '>=', $request->min_price)
                  ->orWhere('price_per_month', '>=', $request->min_price);
            });
        }

        if ($request->has('max_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('price_per_hour', '<=', $request->max_price)
                  ->orWhere('price_per_day', '<=', $request->max_price)
                  ->orWhere('price_per_month', '<=', $request->max_price);
            });
        }

        // 6. فلترة حسب السعة (اختياري)
        if ($request->has('capacity') && $request->capacity) {
            $query->where('capacity', '>=', $request->capacity);
        }

        // 7. البحث بالعنوان (اختياري)
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('address', 'LIKE', "%{$search}%");
            });
        }

        // 8. تحميل العلاقات
        $query->with(['images', 'user']);

        // 9. عدد النتائج في الصفحة
        $perPage = $request->get('per_page', 15);
        if ($perPage > 100) {
            $perPage = 100;
        }

        // 10. جلب النتائج
        $parkingSpots = $query->paginate($perPage);

        // 11. إضافة معلومات إضافية لكل موقف
        $parkingSpots->getCollection()->transform(function ($spot) {
            // تنسيق المسافة
            $spot->distance_km = round($spot->distance, 2);
            $spot->distance_text = $this->formatDistance($spot->distance);
            
            // إضافة أسعار متاحة
            $availablePrices = [];
            if ($spot->price_per_hour !== null) {
                $availablePrices['price_per_hour'] = $spot->price_per_hour;
            }
            if ($spot->price_per_day !== null) {
                $availablePrices['price_per_day'] = $spot->price_per_day;
            }
            if ($spot->price_per_month !== null) {
                $availablePrices['price_per_month'] = $spot->price_per_month;
            }
            $spot->available_prices = $availablePrices;
            $spot->lowest_price = !empty($availablePrices) ? min($availablePrices) : null;
            
            return $spot;
        });

        // 12. إحصائيات
        $stats = [
            'total' => $parkingSpots->total(),
            'radius' => $radius,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        // 13. تسجيل العملية
        Log::info('تم عرض المواقف حسب الأقرب', [
            'user_id' => $user->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius' => $radius,
            'results_count' => $parkingSpots->count(),
            'ip' => $request->ip()
        ]);

        // 14. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => 'تم جلب المواقف حسب الأقرب بنجاح',
            'data' => $parkingSpots,
            'stats' => $stats,
            'filters' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius' => $radius,
                'min_price' => $request->min_price,
                'max_price' => $request->max_price,
                'capacity' => $request->capacity,
                'search' => $request->search,
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
        Log::error('خطأ في قاعدة البيانات أثناء عرض المواقف حسب الأقرب', [
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
        Log::error('خطأ في عرض المواقف حسب الأقرب', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء عرض المواقف حسب الأقرب',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}

/**
 * تنسيق المسافة إلى نص مفهوم
 */
private function formatDistance($distance)
{
    if ($distance < 1) {
        return round($distance * 1000) . ' متر';
    }
    return round($distance, 1) . ' كيلومتر';
}
}