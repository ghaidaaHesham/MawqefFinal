<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ShamPayOwner;
use App\Models\ImageShamPayOwner;
use App\Models\Parking_spot;
use App\Models\NumberSham;
use App\Models\PaymentAmount;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class ShamPayOwnerController extends Controller
{
    /**
     * دفع صاحب الموقف (Owner)
     * 
     * يمكن لصاحب الموقف الدفع في كل مرة يصل فيها:
     * number_sham = booking_number
     * 
     * بعد الدفع الناجح:
     * - يتم إنشاء سجل دفع جديد (يُحتفظ بجميع السجلات السابقة)
     * - يتم تصفير number_sham = 0 لبدء دورة جديدة
     * 
     * @param Request $request
     * - name_in_sham: الاسم في الشام كاش
     * - image: صورة الدفع
     * - parking_id: معرف الموقف
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

            // 2. التحقق من أن المستخدم هو Owner (role = 2)
            $userRole = Role::where('user_id', $user->id)->first();
            
            if (!$userRole || $userRole->role != 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط أصحاب المواقف يمكنهم الدفع',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 3. التحقق من صحة البيانات
            $request->validate([
                'name_in_sham' => 'required|string|max:191',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'parking_id' => 'required|exists:parking_spots,id',
            ], [
                'name_in_sham.required' => 'الاسم في الشام كاش مطلوب',
                'name_in_sham.string' => 'الاسم يجب أن يكون نصاً',
                'name_in_sham.max' => 'الاسم لا يتجاوز 191 حرف',
                
                'image.required' => 'صورة الدفع مطلوبة',
                'image.image' => 'يجب أن تكون الصورة بصيغة صورة',
                'image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, svg',
                'image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
                
                'parking_id.required' => 'معرف الموقف مطلوب',
                'parking_id.exists' => 'الموقف غير موجود',
            ]);

            // 4. التحقق من وجود الموقف
            $parkingSpot = Parking_spot::find($request->parking_id);
            
            if (!$parkingSpot) {
                return response()->json([
                    'success' => false,
                    'message' => 'الموقف غير موجود',
                    'error_type' => 'parking_not_found',
                    'status_code' => 404
                ], 404);
            }

            // 5. التحقق من أن المستخدم هو صاحب الموقف
            if ($parkingSpot->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، هذا الموقف ليس ملكك',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 6. جلب عداد number_sham الخاص بالموقف
            $numberShamRecord = NumberSham::where('id_parking', $parkingSpot->id)->first();
            
            if (!$numberShamRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد عداد حجوزات لهذا الموقف',
                    'error_type' => 'number_sham_not_found',
                    'status_code' => 404
                ], 404);
            }

            // 7. جلب booking_number من جدول paymentamount
            $paymentSettings = PaymentAmount::first();
            
            if (!$paymentSettings || !$paymentSettings->booking_number) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد إعدادات دفع محددة',
                    'error_type' => 'payment_settings_not_found',
                    'status_code' => 404
                ], 404);
            }

            $requiredBookings = $paymentSettings->booking_number;
            $paymentAmount = $paymentSettings->payment_amount;
            $currentNumberSham = $numberShamRecord->number_sham;

            // 8. ✅ التحقق من أن number_sham = booking_number
            if ($currentNumberSham != $requiredBookings) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يمكن الدفع حالياً، يجب أن يصل عدد الحجوزات إلى ' . $requiredBookings . ' حجز',
                    'error_type' => 'threshold_not_reached',
                    'status_code' => 400,
                    'data' => [
                        'parking_id' => $parkingSpot->id,
                        'parking_title' => $parkingSpot->title,
                        'current_number_sham' => $currentNumberSham,
                        'required_bookings' => $requiredBookings,
                        'remaining' => max(0, $requiredBookings - $currentNumberSham),
                    ]
                ], 400);
            }

            // ❌ لا يوجد تحقق من already_paid
            // ✅ يمكن الدفع في كل مرة يتحقق الشرط

            // ✅ 9. بدء Transaction
            DB::beginTransaction();

            try {
                // 10. إنشاء سجل دفع جديد (يُحتفظ بجميع السجلات السابقة)
                $shamPayOwner = ShamPayOwner::create([
                    'user_id' => $user->id, // صاحب الموقف
                    'name_in_sham' => $request->name_in_sham,
                    'parking_id' => $request->parking_id,
                ]);

                // 11. رفع الصورة
                $uploadedImage = null;
                
                if ($request->hasFile('image')) {
                    $image = $request->file('image');
                    
                    if ($image && $image->isValid()) {
                        $imageName = time() . '_pay_' . uniqid() . '.' . $image->getClientOriginalExtension();
                        $path = $image->storeAs('sham_pay_owner', $imageName, 'public');
                        
                        $uploadedImage = ImageShamPayOwner::create([
                            'path' => $path,
                            'title' => $user->name . '_pay_' . time(),
                            'imageable_type' => 'App\Models\ShamPayOwner',
                            'imageable_id' => $shamPayOwner->id,
                        ]);
                    }
                }

                // ✅ 12. تصفير number_sham = 0 لبدء دورة جديدة
                $updated = NumberSham::where('id_parking', $parkingSpot->id)
                    ->update(['number_sham' => 0]);

                if ($updated === 0) {
                    throw new \Exception('فشل تصفير number_sham');
                }

                // تأكيد التصفير
                $numberShamRecord = NumberSham::where('id_parking', $parkingSpot->id)->first();
                $newNumberSham = $numberShamRecord->number_sham;

                if ($newNumberSham !== 0) {
                    throw new \Exception('لم يتم تصفير number_sham بشكل صحيح');
                }

                // ✅ 13. تأكيد Transaction
                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            // 14. تحميل العلاقات
            $shamPayOwner->load('user', 'parking', 'images');

            // 15. حساب عدد مرات الدفع لهذا الموقف
            $paymentCount = ShamPayOwner::where('parking_id', $parkingSpot->id)->count();

            // 16. تسجيل العملية
            Log::info('✅ تم دفع صاحب الموقف وتصفير number_sham إلى 0', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'sham_pay_owner_id' => $shamPayOwner->id,
                'name_in_sham' => $request->name_in_sham,
                'parking_id' => $parkingSpot->id,
                'parking_title' => $parkingSpot->title,
                'old_number_sham' => $currentNumberSham,
                'new_number_sham' => $newNumberSham,
                'required_bookings' => $requiredBookings,
                'payment_amount' => $paymentAmount,
                'payment_count_for_parking' => $paymentCount,
                'has_image' => $uploadedImage ? true : false,
                'ip' => $request->ip()
            ]);

            // 17. إرجاع الاستجابة الناجحة
            return response()->json([
                'success' => true,
                'message' => 'تم دفع صاحب الموقف بنجاح، وتم تصفير number_sham إلى 0 لبدء دورة جديدة',
                'data' => [
                    'sham_pay_owner' => $shamPayOwner,
                    'image' => $uploadedImage,
                    'image_url' => $uploadedImage ? asset('storage/' . $uploadedImage->path) : null,
                    'parking' => [
                        'id' => $parkingSpot->id,
                        'title' => $parkingSpot->title,
                        'address' => $parkingSpot->address,
                    ],
                    'owner' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                    ],
                    'payment_details' => [
                        'number_sham_before' => $currentNumberSham,
                        'number_sham_after' => $newNumberSham,
                        'required_bookings' => $requiredBookings,
                        'payment_amount' => $paymentAmount,
                        'total_amount' => $paymentAmount * $requiredBookings,
                    ],
                    'counter_reset' => [
                        'table' => 'numbersham',
                        'id_parking' => $parkingSpot->id,
                        'old_value' => $currentNumberSham,
                        'new_value' => $newNumberSham,
                        'reset_successfully' => $newNumberSham === 0,
                    ],
                    'payment_history' => [
                        'total_payments_for_parking' => $paymentCount,
                        'message' => 'هذا هو الدفع رقم ' . $paymentCount . ' لهذا الموقف',
                    ],
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
            Log::error('❌ خطأ في قاعدة البيانات أثناء دفع صاحب الموقف', [
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
            Log::error('❌ خطأ في دفع صاحب الموقف', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء دفع صاحب الموقف',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }































    /**
     * عرض مدفوعات موقف معين
     * يمكن لصاحب الموقف (Owner) أو المدير (Admin) عرضها
     *
     * @param Request $request
     * @param int $parkingId معرف الموقف
     */
    public function getByParking(Request $request, $parkingId)
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

            // 2. التحقق من وجود الموقف
            $parkingSpot = Parking_spot::with('user')->find($parkingId);
            
            if (!$parkingSpot) {
                return response()->json([
                    'success' => false,
                    'message' => 'الموقف غير موجود',
                    'error_type' => 'parking_not_found',
                    'status_code' => 404
                ], 404);
            }

            // 3. التحقق من الصلاحية (صاحب الموقف أو المدير)
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
            $isOwner = $parkingSpot->user_id == $user->id;

            if (!$isAdmin && !$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط صاحب الموقف أو المدير يمكنه عرض المدفوعات',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 4. جلب جميع مدفوعات الموقف مع الصور
            $payments = ShamPayOwner::where('parking_id', $parkingId)
                ->with(['user', 'images'])
                ->orderBy('created_at', 'desc')
                ->get();

            // 5. تنسيق البيانات
            $formattedPayments = $payments->map(function ($payment, $index) use ($payments) {
                // جلب صورة الدفع
                $image = ImageShamPayOwner::where('imageable_id', $payment->id)
                    ->where('imageable_type', 'App\Models\ShamPayOwner')
                    ->first();

                return [
                    'id' => $payment->id,
                    'payment_number' => $payments->count() - $index, // رقم الدفعة (من الأحدث للأقدم)
                    'user_id' => $payment->user_id,
                    'name_in_sham' => $payment->name_in_sham,
                    'parking_id' => $payment->parking_id,
                    'owner' => $payment->user ? [
                        'id' => $payment->user->id,
                        'name' => $payment->user->name,
                        'phone' => $payment->user->phone,
                    ] : null,
                    'image' => $image,
                    'image_url' => $image ? asset('storage/' . $image->path) : null,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                ];
            });

            // 6. إحصائيات المدفوعات
            $totalPayments = $payments->count();
            $totalAmount = 0;
            
            // جلب إعدادات الدفع لحساب الإجمالي
            $paymentSettings = \App\Models\PaymentAmount::first();
            if ($paymentSettings) {
                $totalAmount = $totalPayments * $paymentSettings->payment_amount * $paymentSettings->booking_number;
            }

            // 7. تسجيل العملية
            Log::info('تم عرض مدفوعات الموقف', [
                'user_id' => $user->id,
                'user_role' => $isAdmin ? 'Admin' : 'Owner',
                'parking_id' => $parkingId,
                'parking_title' => $parkingSpot->title,
                'payments_count' => $totalPayments,
                'ip' => $request->ip()
            ]);

            // 8. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => 'تم جلب مدفوعات الموقف بنجاح',
                'data' => [
                    'parking' => [
                        'id' => $parkingSpot->id,
                        'title' => $parkingSpot->title,
                        'address' => $parkingSpot->address,
                        'owner' => [
                            'id' => $parkingSpot->user->id ?? null,
                            'name' => $parkingSpot->user->name ?? null,
                            'phone' => $parkingSpot->user->phone ?? null,
                        ],
                    ],
                    'payments' => $formattedPayments,
                    'statistics' => [
                        'total_payments' => $totalPayments,
                        'total_amount' => $totalAmount,
                        'payment_settings' => $paymentSettings ? [
                            'payment_amount' => $paymentSettings->payment_amount,
                            'booking_number' => $paymentSettings->booking_number,
                        ] : null,
                    ],
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('خطأ في عرض مدفوعات الموقف', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id ?? null,
                'parking_id' => $parkingId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء عرض المدفوعات',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }




































    /**
     * عرض جميع مدفوعات صاحب الموقف الحالي (جميع مواقفه)
     *
     * @param Request $request
     */
    public function getMyPayments(Request $request)
    {
        try {
            // ============================================
            // 1. التحقق من الصلاحيات
            // ============================================
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
                    'message' => 'غير مصرح لك، فقط أصحاب المواقف يمكنهم عرض مدفوعاتهم',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // ============================================
            // 2. بناء الاستعلام مع الفلاتر
            // ============================================
            $query = ShamPayOwner::where('user_id', $user->id)
                ->with(['parking']);

            // فلترة حسب الموقف
            if ($request->has('parking_id') && $request->parking_id) {
                $parkingSpot = Parking_spot::where('id', $request->parking_id)
                    ->where('user_id', $user->id)
                    ->first();
                
                if (!$parkingSpot) {
                    return response()->json([
                        'success' => false,
                        'message' => 'الموقف غير موجود أو ليس ملكك',
                        'error_type' => 'parking_not_found',
                        'status_code' => 404
                    ], 404);
                }
                
                $query->where('parking_id', $request->parking_id);
            }

            // فلترة حسب الاسم في الشام
            if ($request->has('name_in_sham') && $request->name_in_sham) {
                $query->where('name_in_sham', 'LIKE', '%' . $request->name_in_sham . '%');
            }

            // فلترة حسب التاريخ
            if ($request->has('from_date') && $request->from_date) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date') && $request->to_date) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // ترتيب النتائج
            $sortField = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $allowedSortFields = ['id', 'name_in_sham', 'created_at', 'updated_at'];
            
            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortOrder);
            }

            // ============================================
            // 3. جلب البيانات مع الترقيم
            // ============================================
            $perPage = $request->get('per_page', 15);
            if ($perPage > 100) {
                $perPage = 100;
            }

            $payments = $query->paginate($perPage);

            // ============================================
            // 4. جلب إعدادات الدفع
            // ============================================
            $paymentSettings = PaymentAmount::first();
            $paymentAmount = $paymentSettings ? $paymentSettings->payment_amount : 0;
            $bookingNumber = $paymentSettings ? $paymentSettings->booking_number : 0;
            $totalAmountPerPayment = $paymentAmount * $bookingNumber;

            // ============================================
            // 5. تنسيق قائمة المدفوعات
            // ============================================
            $formattedPayments = $payments->getCollection()->map(function ($payment) use ($paymentAmount, $bookingNumber, $totalAmountPerPayment) {
                $image = ImageShamPayOwner::where('imageable_id', $payment->id)
                    ->where('imageable_type', 'App\Models\ShamPayOwner')
                    ->first();

                return [
                    // ===== معلومات الدفع =====
                    'payment_info' => [
                        'id' => $payment->id,
                        'name_in_sham' => $payment->name_in_sham,
                        'amount' => $paymentAmount,
                        'bookings_count' => $bookingNumber,
                        'total_amount' => $totalAmountPerPayment,
                        'created_at' => $payment->created_at,
                        'created_at_human' => $payment->created_at->diffForHumans(),
                    ],
                    
                    // ===== معلومات الموقف =====
                    'parking_info' => [
                        'id' => $payment->parking_id,
                        'title' => $payment->parking->title ?? null,
                        'address' => $payment->parking->address ?? null,
                    ],
                    
                    // ===== صورة الدفع =====
                    'payment_image' => [
                        'id' => $image->id ?? null,
                        'path' => $image->path ?? null,
                        'url' => $image ? asset('storage/' . $image->path) : null,
                    ],
                ];
            });

            $payments->setCollection($formattedPayments);

            // ============================================
            // 6. إحصائيات عامة
            // ============================================
            $totalPayments = ShamPayOwner::where('user_id', $user->id)->count();
            $totalAmount = $totalPayments * $totalAmountPerPayment;
            $uniqueParkings = ShamPayOwner::where('user_id', $user->id)
                ->distinct('parking_id')
                ->count('parking_id');

            // إحصائيات حسب الفلتر الحالي
            $filteredCount = $payments->total();
            $filteredAmount = $filteredCount * $totalAmountPerPayment;

            // ============================================
            // 7. إحصائيات لكل موقف
            // ============================================
            $myParkingSpots = Parking_spot::where('user_id', $user->id)
                ->select('id', 'title', 'address')
                ->get();

            $parkingsStats = $myParkingSpots->map(function ($spot) use ($paymentAmount, $bookingNumber, $totalAmountPerPayment) {
                $paymentCount = ShamPayOwner::where('user_id', $spot->user_id ?? null)
                    ->where('parking_id', $spot->id)
                    ->count();
                
                // جلب آخر دفعة لهذا الموقف
                $lastPayment = ShamPayOwner::where('parking_id', $spot->id)
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                return [
                    'parking_info' => [
                        'id' => $spot->id,
                        'title' => $spot->title,
                        'address' => $spot->address,
                    ],
                    'payments_info' => [
                        'total_payments' => $paymentCount,
                        'total_amount' => $paymentCount * $totalAmountPerPayment,
                        'last_payment_date' => $lastPayment ? $lastPayment->created_at : null,
                        'last_payment_date_human' => $lastPayment ? $lastPayment->created_at->diffForHumans() : null,
                    ],
                ];
            });

            // ============================================
            // 8. تسجيل العملية
            // ============================================
            Log::info('تم عرض مدفوعات صاحب الموقف', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'total_payments' => $totalPayments,
                'filtered_count' => $filteredCount,
                'ip' => $request->ip()
            ]);

            // ============================================
            // 9. إرجاع الاستجابة المنظمة
            // ============================================
            return response()->json([
                'success' => true,
                'message' => 'تم جلب مدفوعاتك بنجاح',
                
                // ===== الإحصائيات العامة =====
                'summary' => [
                    'total_payments' => $totalPayments,
                    'total_amount' => $totalAmount,
                    'total_parkings' => $myParkingSpots->count(),
                    'parkings_with_payments' => $uniqueParkings,
                    'parkings_without_payments' => $myParkingSpots->count() - $uniqueParkings,
                ],
                
                // ===== إعدادات الدفع الحالية =====
                'payment_settings' => [
                    'payment_amount' => $paymentAmount,
                    'booking_number' => $bookingNumber,
                    'total_amount_per_payment' => $totalAmountPerPayment,
                ],
                
                // ===== المدفوعات (مع الترقيم) =====
                'payments' => [
                    'data' => $payments->items(),
                    'pagination' => [
                        'total' => $payments->total(),
                        'per_page' => $payments->perPage(),
                        'current_page' => $payments->currentPage(),
                        'last_page' => $payments->lastPage(),
                        'from' => $payments->firstItem(),
                        'to' => $payments->lastItem(),
                    ],
                ],
                
                // ===== إحصائيات لكل موقف =====
                'parkings_stats' => $parkingsStats,
                
                // ===== الفلاتر المطبقة =====
                'filters' => [
                    'parking_id' => $request->parking_id,
                    'name_in_sham' => $request->name_in_sham,
                    'from_date' => $request->from_date,
                    'to_date' => $request->to_date,
                    'sort_by' => $sortField,
                    'sort_order' => $sortOrder,
                    'per_page' => $perPage,
                ],
                
                // ===== ملخص الفلترة =====
                'filter_summary' => [
                    'filtered_count' => $filteredCount,
                    'filtered_amount' => $filteredAmount,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('خطأ في عرض مدفوعات صاحب الموقف', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء عرض المدفوعات',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }



    /**
     * عرض جميع المدفوعات لكل المواقف من الأحدث إلى الأقدم (للمدير فقط)
     *
     * @param Request $request
     */
    public function getAllPayments(Request $request)
    {
        try {
            // ============================================
            // 1. التحقق من الصلاحيات
            // ============================================
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
            
            if (!$userRole || $userRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط المدير يمكنه عرض جميع المدفوعات',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // ============================================
            // 2. جلب إعدادات الدفع
            // ============================================
            $paymentSettings = PaymentAmount::first();
            $paymentAmount = $paymentSettings ? $paymentSettings->payment_amount : 0;
            $bookingNumber = $paymentSettings ? $paymentSettings->booking_number : 0;
            $totalAmountPerPayment = $paymentAmount * $bookingNumber;

            // ============================================
            // 3. بناء الاستعلام - من الأحدث إلى الأقدم
            // ============================================
            $query = ShamPayOwner::with(['user', 'parking'])
                ->orderBy('created_at', 'desc');

            // فلترة حسب الموقف (اختياري)
            if ($request->has('parking_id') && $request->parking_id) {
                $query->where('parking_id', $request->parking_id);
            }

            // فلترة حسب صاحب الموقف (اختياري)
            if ($request->has('user_id') && $request->user_id) {
                $query->where('user_id', $request->user_id);
            }

            // فلترة حسب الاسم في الشام (اختياري)
            if ($request->has('name_in_sham') && $request->name_in_sham) {
                $query->where('name_in_sham', 'LIKE', '%' . $request->name_in_sham . '%');
            }

            // فلترة حسب التاريخ (اختياري)
            if ($request->has('from_date') && $request->from_date) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date') && $request->to_date) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // ============================================
            // 4. جلب البيانات مع الترقيم
            // ============================================
            $perPage = $request->get('per_page', 15);
            if ($perPage > 100) {
                $perPage = 100;
            }

            $payments = $query->paginate($perPage);

            // ============================================
            // 5. تنسيق قائمة المدفوعات
            // ============================================
            $formattedPayments = $payments->getCollection()->map(function ($payment) use ($paymentAmount, $bookingNumber, $totalAmountPerPayment) {
                $image = ImageShamPayOwner::where('imageable_id', $payment->id)
                    ->where('imageable_type', 'App\Models\ShamPayOwner')
                    ->first();

                return [
                    // ===== معلومات الدفع =====
                    'payment_info' => [
                        'id' => $payment->id,
                        'name_in_sham' => $payment->name_in_sham,
                        'amount' => $paymentAmount,
                        'bookings_count' => $bookingNumber,
                        'total_amount' => $totalAmountPerPayment,
                        'created_at' => $payment->created_at,
                        'created_at_human' => $payment->created_at->diffForHumans(),
                    ],
                    
                    // ===== معلومات صاحب الموقف =====
                    'owner_info' => [
                        'id' => $payment->user_id,
                        'name' => $payment->user->name ?? null,
                        'phone' => $payment->user->phone ?? null,
                    ],
                    
                    // ===== معلومات الموقف =====
                    'parking_info' => [
                        'id' => $payment->parking_id,
                        'title' => $payment->parking->title ?? null,
                        'address' => $payment->parking->address ?? null,
                    ],
                    
                    // ===== صورة الدفع =====
                    'payment_image' => [
                        'id' => $image->id ?? null,
                        'path' => $image->path ?? null,
                        'url' => $image ? asset('storage/' . $image->path) : null,
                    ],
                ];
            });

            $payments->setCollection($formattedPayments);

            // ============================================
            // 6. الإحصائيات العامة
            // ============================================
            $totalPayments = ShamPayOwner::count();
            $totalAmount = $totalPayments * $totalAmountPerPayment;
            $uniqueParkings = ShamPayOwner::distinct('parking_id')->count('parking_id');
            $uniqueOwners = ShamPayOwner::distinct('user_id')->count('user_id');

            // ============================================
            // 7. تسجيل العملية
            // ============================================
            Log::info('تم عرض جميع المدفوعات من الأحدث إلى الأقدم', [
                'admin_id' => $user->id,
                'admin_name' => $user->name,
                'total_payments' => $totalPayments,
                'ip' => $request->ip()
            ]);

            // ============================================
            // 8. إرجاع الاستجابة المنظمة
            // ============================================
            return response()->json([
                'success' => true,
                'message' => 'تم جلب جميع المدفوعات بنجاح (من الأحدث إلى الأقدم)',
                
                // ===== الإحصائيات العامة =====
                'summary' => [
                    'total_payments' => $totalPayments,
                    'total_amount' => $totalAmount,
                    'unique_parkings' => $uniqueParkings,
                    'unique_owners' => $uniqueOwners,
                ],
                
                // ===== إعدادات الدفع الحالية =====
                'payment_settings' => [
                    'payment_amount' => $paymentAmount,
                    'booking_number' => $bookingNumber,
                    'total_amount_per_payment' => $totalAmountPerPayment,
                ],
                
                // ===== المدفوعات (من الأحدث إلى الأقدم) =====
                'payments' => [
                    'data' => $payments->items(),
                    'pagination' => [
                        'total' => $payments->total(),
                        'per_page' => $payments->perPage(),
                        'current_page' => $payments->currentPage(),
                        'last_page' => $payments->lastPage(),
                        'from' => $payments->firstItem(),
                        'to' => $payments->lastItem(),
                    ],
                ],
                
                // ===== الفلاتر المطبقة =====
                'filters' => [
                    'parking_id' => $request->parking_id,
                    'user_id' => $request->user_id,
                    'name_in_sham' => $request->name_in_sham,
                    'from_date' => $request->from_date,
                    'to_date' => $request->to_date,
                    'sort_by' => 'created_at',
                    'sort_order' => 'desc',
                    'per_page' => $perPage,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('خطأ في عرض جميع المدفوعات', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء عرض المدفوعات',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }
}