<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sham_cash;
use App\Models\ImageShamCash;
use App\Models\Booking;
use App\Models\User;
use App\Models\Role;
use App\Models\Parking_spot;
use App\Models\NumberSham;
use App\Models\PaymentAmount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class ShamCashController extends Controller
{
    // /**
    //  * إضافة معلومات الشام كاش (الاسم والصورة) مع ربطها بحجز معين
    //  * فقط صاحب الحجز يمكنه الدفع بالشام كاش
    //  */
    // public function store(Request $request)
    // {
    //     try {
    //         // 1. التحقق من أن المستخدم مسجل دخول
    //         $user = $request->user();
            
    //         if (!$user) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
    //                 'error_type' => 'unauthenticated',
    //                 'status_code' => 401
    //             ], 401);
    //         }

    //         // 2. التحقق من صحة البيانات
    //         $request->validate([
    //             'name_in_sham' => 'required|string|max:191',
    //             'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
    //             'booking_id' => 'required|exists:bookings,id',
    //         ], [
    //             'name_in_sham.required' => 'الاسم في الشام كاش مطلوب',
    //             'name_in_sham.string' => 'الاسم يجب أن يكون نصاً',
    //             'name_in_sham.max' => 'الاسم لا يتجاوز 191 حرف',
                
    //             'image.required' => 'صورة الدفع مطلوبة',
    //             'image.image' => 'يجب أن تكون الصورة بصيغة صورة',
    //             'image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
    //             'image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
                
    //             'booking_id.required' => 'معرف الحجز مطلوب',
    //             'booking_id.exists' => 'الحجز غير موجود',
    //         ]);

    //         // 3. التحقق من وجود الحجز
    //         $booking = Booking::find($request->booking_id);
            
    //         if (!$booking) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'الحجز غير موجود',
    //                 'error_type' => 'booking_not_found',
    //                 'status_code' => 404
    //             ], 404);
    //         }

    //         // 4. التحقق من أن المستخدم هو صاحب الحجز (المستأجر)
    //         if ($booking->user_id != $user->id) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'غير مصرح لك، فقط صاحب الحجز يمكنه الدفع بالشام كاش',
    //                 'error_type' => 'unauthorized',
    //                 'status_code' => 403
    //             ], 403);
    //         }

    //         // 5. التحقق من أن الحجز في حالة مناسبة للدفع
    //         if (!in_array($booking->status, ['pending', 'paid'])) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'لا يمكن الدفع بالشام كاش لهذا الحجز لأنه ' . $booking->status,
    //                 'error_type' => 'invalid_booking_status',
    //                 'status_code' => 400
    //             ], 400);
    //         }

    //         // 6. التحقق من عدم وجود شام كاش مسبق لهذا الحجز
    //         $existingSham = Sham_cash::where('booking_id', $request->booking_id)->first();
            
    //         if ($existingSham) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'تم الدفع لهذا الحجز مسبقاً',
    //                 'error_type' => 'booking_already_paid',
    //                 'status_code' => 400,
    //                 'data' => [
    //                     'sham_cash' => $existingSham,
    //                 ]
    //             ], 400);
    //         }

    //         // 7. التحقق من وجود معلومات شام كاش سابقة للمستخدم (اختياري)
    //         $existingUserSham = Sham_cash::where('user_id', $user->id)->first();
            
    //         if ($existingUserSham) {
    //             // حذف الصورة القديمة
    //             $oldImage = ImageShamCash::where('imageable_id', $existingUserSham->id)
    //                 ->where('imageable_type', 'App\Models\Sham_cash')
    //                 ->first();
                
    //             if ($oldImage) {
    //                 if (Storage::disk('public')->exists($oldImage->path)) {
    //                     Storage::disk('public')->delete($oldImage->path);
    //                 }
    //                 $oldImage->delete();
    //             }
                
    //             // حذف السجل القديم
    //             $existingUserSham->delete();
    //         }

    //         // 8. حفظ معلومات الشام كاش
    //         $shamCash = Sham_cash::create([
    //             'user_id' => $user->id,
    //             'name_in_sham' => $request->name_in_sham,
    //             'booking_id' => $request->booking_id,
    //         ]);

    //         // 9. رفع الصورة
    //         $uploadedImage = null;
            
    //         if ($request->hasFile('image')) {
    //             $image = $request->file('image');
                
    //             if ($image && $image->isValid()) {
    //                 // إنشاء اسم فريد للصورة
    //                 $imageName = time() . '_sham_' . uniqid() . '.' . $image->getClientOriginalExtension();
                    
    //                 // حفظ الصورة في مجلد sham_cash
    //                 $path = $image->storeAs('sham_cash', $imageName, 'public');
                    
    //                 // حفظ في جدول image_sham_cash
    //                 $uploadedImage = ImageShamCash::create([
    //                     'path' => $path,
    //                     'title' => $user->name . '_sham_' . time(),
    //                     'imageable_type' => 'App\Models\Sham_cash',
    //                     'imageable_id' => $shamCash->id,
    //                 ]);
    //             }
    //         }

    //         // 10. تحديث حالة الحجز إلى "paid" بعد الدفع
    //         $booking->status = 'paid';
    //         $booking->save();

    //         // 11. تحميل العلاقات
    //         $shamCash->load('user', 'booking', 'images');

    //         // 12. تسجيل العملية
    //         Log::info('تم الدفع بالشام كاش', [
    //             'user_id' => $user->id,
    //             'name' => $user->name,
    //             'phone' => $user->phone,
    //             'sham_cash_id' => $shamCash->id,
    //             'name_in_sham' => $request->name_in_sham,
    //             'booking_id' => $request->booking_id,
    //             'booking_status' => $booking->status,
    //             'has_image' => $uploadedImage ? true : false,
    //             'ip' => $request->ip()
    //         ]);

    //         // 13. إرجاع الاستجابة
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'تم الدفع بالشام كاش بنجاح',
    //             'data' => [
    //                 'sham_cash' => $shamCash,
    //                 'image' => $uploadedImage,
    //                 'image_url' => $uploadedImage ? asset('storage/' . $uploadedImage->path) : null,
    //                 'booking' => [
    //                     'id' => $booking->id,
    //                     'start_time' => $booking->start_time,
    //                     'end_time' => $booking->end_time,
    //                     'status' => $booking->status,
    //                     'status_text' => 'تم الدفع',
    //                 ],
    //             ]
    //         ], 201);

    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'فشل التحقق من البيانات',
    //             'errors' => $e->errors(),
    //             'error_type' => 'validation_error',
    //             'status_code' => 422
    //         ], 422);

    //     } catch (QueryException $e) {
    //         Log::error('خطأ في قاعدة البيانات أثناء الدفع بالشام كاش', [
    //             'error' => $e->getMessage(),
    //             'user_id' => $request->user()?->id ?? null
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'حدث خطأ في قاعدة البيانات',
    //             'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
    //             'error_type' => 'database_error',
    //             'status_code' => 500
    //         ], 500);

    //     } catch (\Exception $e) {
    //         Log::error('خطأ في الدفع بالشام كاش', [
    //             'error' => $e->getMessage(),
    //             'user_id' => $request->user()?->id
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'حدث خطأ أثناء الدفع بالشام كاش',
    //             'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
    //             'error_type' => 'general_error',
    //             'status_code' => 500
    //         ], 500);
    //     }
    // }

/**
 * إضافة معلومات الشام كاش (الاسم والصورة) مع ربطها بحجز معين
 * فقط صاحب الحجز يمكنه الدفع بالشام كاش
 * + زيادة عداد number_sham للموقف والتحقق من booking_number
 * + الحفاظ على جميع البيانات القديمة (عدم الحذف)
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

        // 2. التحقق من صحة البيانات
        $request->validate([
            'name_in_sham' => 'required|string|max:191',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'booking_id' => 'required|exists:bookings,id',
        ], [
            'name_in_sham.required' => 'الاسم في الشام كاش مطلوب',
            'name_in_sham.string' => 'الاسم يجب أن يكون نصاً',
            'name_in_sham.max' => 'الاسم لا يتجاوز 191 حرف',
            
            'image.required' => 'صورة الدفع مطلوبة',
            'image.image' => 'يجب أن تكون الصورة بصيغة صورة',
            'image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
            'image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
            
            'booking_id.required' => 'معرف الحجز مطلوب',
            'booking_id.exists' => 'الحجز غير موجود',
        ]);

        // 3. التحقق من وجود الحجز
        $booking = Booking::find($request->booking_id);
        
        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'الحجز غير موجود',
                'error_type' => 'booking_not_found',
                'status_code' => 404
            ], 404);
        }

        // 4. التحقق من أن المستخدم هو صاحب الحجز (المستأجر)
        if ($booking->user_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك، فقط صاحب الحجز يمكنه الدفع بالشام كاش',
                'error_type' => 'unauthorized',
                'status_code' => 403
            ], 403);
        }

        // 5. التحقق من أن الحجز في حالة مناسبة للدفع
        if (!in_array($booking->status, ['pending', 'paid'])) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن الدفع بالشام كاش لهذا الحجز لأنه ' . $booking->status,
                'error_type' => 'invalid_booking_status',
                'status_code' => 400
            ], 400);
        }

        // 6. التحقق من عدم وجود شام كاش مسبق لهذا الحجز
        $existingSham = Sham_cash::where('booking_id', $request->booking_id)->first();
        
        if ($existingSham) {
            return response()->json([
                'success' => false,
                'message' => 'تم الدفع لهذا الحجز مسبقاً',
                'error_type' => 'booking_already_paid',
                'status_code' => 400,
                'data' => [
                    'sham_cash' => $existingSham,
                ]
            ], 400);
        }

        // ✅ 7. جلب الموقف المرتبط بالحجز
        $parkingSpot = Parking_spot::find($booking->spot_id);
        
        if (!$parkingSpot) {
            return response()->json([
                'success' => false,
                'message' => 'الموقف المرتبط بالحجز غير موجود',
                'error_type' => 'parking_not_found',
                'status_code' => 404
            ], 404);
        }

        // ✅ 8. جلب أو إنشاء سطر NumberSham للموقف
        $numberSham = NumberSham::where('id_parking', $parkingSpot->id)->first();
        
        if (!$numberSham) {
            // إنشاء سطر جديد إذا لم يكن موجوداً
            $numberSham = NumberSham::create([
                'number_sham' => 0,
                'id_parking' => $parkingSpot->id,
            ]);
        }

        // ✅ 9. جلب إعدادات الدفع (booking_number)
        $paymentSettings = PaymentAmount::first();
        $requiredBookings = $paymentSettings ? $paymentSettings->booking_number : null;

        // ✅ 10. التحقق من أن العداد لم يصل للحد المطلوب
        if ($requiredBookings && $numberSham->number_sham >= $requiredBookings) {
            return response()->json([
                'success' => false,
                'message' => 'تم استكمال عدد الحجوزات المطلوبة لهذا الموقف، يرجى دفع المبلغ',
                'error_type' => 'payment_required',
                'status_code' => 400,
                'data' => [
                    'parking_id' => $parkingSpot->id,
                    'parking_title' => $parkingSpot->title,
                    'number_sham' => $numberSham->number_sham,
                    'booking_number' => $requiredBookings,
                ]
            ], 400);
        }

        // ✅ 11. حفظ معلومات الشام كاش الجديدة (بدون حذف القديمة)
        $shamCash = Sham_cash::create([
            'user_id' => $user->id,
            'name_in_sham' => $request->name_in_sham,
            'booking_id' => $request->booking_id,
        ]);

        // ✅ 12. رفع الصورة الجديدة (بدون حذف القديمة)
        $uploadedImage = null;
        
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            
            if ($image && $image->isValid()) {
                // إنشاء اسم فريد للصورة
                $imageName = time() . '_sham_' . uniqid() . '.' . $image->getClientOriginalExtension();
                
                // حفظ الصورة في مجلد sham_cash
                $path = $image->storeAs('sham_cash', $imageName, 'public');
                
                // حفظ في جدول image_sham_cash
                $uploadedImage = ImageShamCash::create([
                    'path' => $path,
                    'title' => $user->name . '_sham_' . time(),
                    'imageable_type' => 'App\Models\Sham_cash',
                    'imageable_id' => $shamCash->id,
                ]);
            }
        }

        // 13. تحديث حالة الحجز إلى "paid" بعد الدفع
        $booking->status = 'paid';
        $booking->save();

        // ✅ 14. زيادة عداد number_sham بمقدار 1
        $oldNumberSham = $numberSham->number_sham;
        $numberSham->number_sham = $numberSham->number_sham + 1;
        $numberSham->save();

        // ✅ 15. التحقق من وصول العداد إلى booking_number
        $thresholdReached = false;
        if ($requiredBookings && $numberSham->number_sham >= $requiredBookings) {
            $thresholdReached = true;

            // إرسال إشعار لصاحب الموقف
            try {
                $owner = User::find($parkingSpot->user_id);
                
                if ($owner) {
                    $message = "🔔 تنبيه دفع\n\n";
                    $message .= "تم استكمال عدد الحجوزات المطلوبة للموقف: {$parkingSpot->title}\n";
                    $message .= "عدد الحجوزات: {$numberSham->number_sham}\n";
                    $message .= "المبلغ المطلوب: " . ($paymentSettings->payment_amount ?? 'غير محدد') . " ل.س\n";
                    $message .= "الرجاء دفع المبلغ المطلوب.";

                    Log::info('🔔 يجب إرسال إشعار لصاحب الموقف', [
                        'owner_id' => $owner->id,
                        'owner_name' => $owner->name,
                        'parking_id' => $parkingSpot->id,
                        'parking_title' => $parkingSpot->title,
                        'number_sham' => $numberSham->number_sham,
                        'required_bookings' => $requiredBookings,
                        'payment_amount' => $paymentSettings->payment_amount ?? null,
                        'message' => $message,
                    ]);

                    // يمكنك إضافة إرسال FCM هنا
                    // $this->sendFCMNotification($owner, $parkingSpot, $message);
                }
            } catch (\Exception $e) {
                Log::error('خطأ في إرسال إشعار الدفع', [
                    'error' => $e->getMessage(),
                    'parking_id' => $parkingSpot->id
                ]);
            }
        }

        // 16. تحميل العلاقات
        $shamCash->load('user', 'booking', 'images');

        // 17. تسجيل العملية
        Log::info('تم الدفع بالشام كاش', [
            'user_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'sham_cash_id' => $shamCash->id,
            'name_in_sham' => $request->name_in_sham,
            'booking_id' => $request->booking_id,
            'booking_status' => $booking->status,
            'parking_id' => $parkingSpot->id,
            'parking_title' => $parkingSpot->title,
            'old_number_sham' => $oldNumberSham,
            'new_number_sham' => $numberSham->number_sham,
            'required_bookings' => $requiredBookings,
            'threshold_reached' => $thresholdReached,
            'has_image' => $uploadedImage ? true : false,
            'ip' => $request->ip()
        ]);

        // 18. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => 'تم الدفع بالشام كاش بنجاح',
            'data' => [
                'sham_cash' => $shamCash,
                'image' => $uploadedImage,
                'image_url' => $uploadedImage ? asset('storage/' . $uploadedImage->path) : null,
                'booking' => [
                    'id' => $booking->id,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                    'status' => $booking->status,
                    'status_text' => 'تم الدفع',
                ],
                'parking' => [
                    'id' => $parkingSpot->id,
                    'title' => $parkingSpot->title,
                    'address' => $parkingSpot->address,
                ],
                'number_sham' => [
                    'id' => $numberSham->id,
                    'number_sham' => $numberSham->number_sham,
                    'id_parking' => $numberSham->id_parking,
                    'old_number_sham' => $oldNumberSham,
                ],
                'payment_status' => [
                    'required_bookings' => $requiredBookings,
                    'current_number_sham' => $numberSham->number_sham,
                    'remaining' => $requiredBookings ? max(0, $requiredBookings - $numberSham->number_sham) : null,
                    'threshold_reached' => $thresholdReached,
                    'payment_amount' => $paymentSettings->payment_amount ?? null,
                ],
                'owner_notified' => $thresholdReached,
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
        Log::error('خطأ في قاعدة البيانات أثناء الدفع بالشام كاش', [
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
        Log::error('خطأ في الدفع بالشام كاش', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء الدفع بالشام كاش',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type' => 'general_error',
            'status_code' => 500
        ], 500);
    }
}

    
    /**
     * عرض معلومات الشام كاش لحجز معين
     */
    public function getByBooking(Request $request, $bookingId)
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

            $booking = Booking::find($bookingId);
            
            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'الحجز غير موجود',
                    'error_type' => 'booking_not_found',
                    'status_code' => 404
                ], 404);
            }

            // التحقق من الصلاحية (صاحب الحجز أو صاحب الموقف أو مدير)
            $userRole = Role::where('user_id', $user->id)->first();
            $isRenter = $booking->user_id == $user->id;
            $isOwner = $booking->parkingSpot && $booking->parkingSpot->user_id == $user->id;
            $isAdmin = $userRole && $userRole->role == 1;

            if (!$isRenter && !$isOwner && !$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك بمشاهدة هذه المعلومات',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            $shamCash = Sham_cash::where('booking_id', $bookingId)
                ->with(['user', 'booking', 'images'])
                ->first();

            if (!$shamCash) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد معلومات شام كاش لهذا الحجز',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            $image = ImageShamCash::where('imageable_id', $shamCash->id)
                ->where('imageable_type', 'App\Models\Sham_cash')
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب معلومات الشام كاش للحجز بنجاح',
                'data' => [
                    'sham_cash' => $shamCash,
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
}