<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Parking_spot;
use App\Models\User;
use App\Models\Role;
use App\Models\BookingCancellation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Carbon\Carbon;

class BookingController extends Controller
{
    /**
     * حجز موقف معين
     */
    public function storeBooking(Request $request)
    {
        try {
            // 1. التحقق من أن المستخدم مسجل دخول (هذا هو المستأجر)
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
                'spot_id' => 'required|exists:parking_spots,id',
                'start_time' => 'required|date|after:now',
                'end_time' => 'required|date|after:start_time',
            ], [
                'spot_id.required' => 'معرف الموقف مطلوب',
                'spot_id.exists' => 'الموقف غير موجود',
                'start_time.required' => 'وقت البداية مطلوب',
                'start_time.date' => 'وقت البداية يجب أن يكون تاريخاً صحيحاً',
                'start_time.after' => 'وقت البداية يجب أن يكون بعد الوقت الحالي',
                'end_time.required' => 'وقت النهاية مطلوب',
                'end_time.date' => 'وقت النهاية يجب أن يكون تاريخاً صحيحاً',
                'end_time.after' => 'وقت النهاية يجب أن يكون بعد وقت البداية',
            ]);

            // 3. البحث عن الموقف
            $parkingSpot = Parking_spot::find($request->spot_id);
            
            if (!$parkingSpot) {
                return response()->json([
                    'success' => false,
                    'message' => 'الموقف غير موجود',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // 4. التحقق من أن الموقف متاح ونشط
            if ($parkingSpot->is_available != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'الموقف غير متاح حالياً',
                    'error_type' => 'not_available',
                    'status_code' => 400
                ], 400);
            }

            if ($parkingSpot->is_active != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'الموقف غير مفعل حالياً',
                    'error_type' => 'not_active',
                    'status_code' => 400
                ], 400);
            }

            // 5. التحقق من عدم وجود حجز متداخل مع مراعاة السعة
            $startTime = Carbon::parse($request->start_time);
            $endTime = Carbon::parse($request->end_time);

            // جلب جميع الحجوزات النشطة في نفس الفترة
            $activeBookings = Booking::where('spot_id', $request->spot_id)
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->where(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '>=', $startTime)
                          ->where('start_time', '<', $endTime);
                    })->orWhere(function ($q) use ($startTime, $endTime) {
                        $q->where('end_time', '>', $startTime)
                          ->where('end_time', '<=', $endTime);
                    })->orWhere(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '<=', $startTime)
                          ->where('end_time', '>=', $endTime);
                    });
                })
                ->whereIn('status', ['pending', 'paid', 'active'])
                ->get();

            $activeCount = $activeBookings->count();

            // التحقق من السعة المتاحة
            if ($activeCount >= $parkingSpot->capacity) {
                $overlappingBookings = $activeBookings->map(function ($booking) {
                    return [
                        'id' => $booking->id,
                        'start_time' => $booking->start_time,
                        'end_time' => $booking->end_time,
                        'status' => $booking->status,
                    ];
                });

                return response()->json([
                    'success' => false,
                    'message' => 'الموقف ممتلئ في هذا التوقيت، السعة القصوى ' . $parkingSpot->capacity . ' موقف',
                    'error_type' => 'fully_booked',
                    'status_code' => 400,
                    'data' => [
                        'capacity' => $parkingSpot->capacity,
                        'current_bookings' => $activeCount,
                        'available_spots' => 0,
                        'overlapping_bookings' => $overlappingBookings,
                    ]
                ], 400);
            }

            // 6. حساب المبلغ الكلي (مع دعم الكسور)
            // حساب الفرق بالدقائق للحصول على دقة أكبر
            $totalMinutes = $endTime->diffInMinutes($startTime);
            $totalHours = $totalMinutes / 60;
            $totalDays = $totalHours / 24;
            
            $totalAmount = 0;
            $priceBreakdown = [];
            $calculationMethod = '';

            // 6.1 حساب المبلغ بناءً على المدة
            // إذا كانت المدة أقل من يوم (استخدام الساعة مع الكسور)
            if ($totalDays < 1) {
                if ($parkingSpot->price_per_hour) {
                    // حساب السعر بدقة مع الكسور (نصف ساعة، ربع ساعة، إلخ)
                    $totalAmount = $totalHours * $parkingSpot->price_per_hour;
                    $calculationMethod = 'per_hour';
                    $priceBreakdown = [
                        'method' => 'حساب بالساعة (مع الكسور)',
                        'hours' => round($totalHours, 2),
                        'minutes' => $totalMinutes,
                        'price_per_hour' => $parkingSpot->price_per_hour,
                        'total' => round($totalAmount, 2)
                    ];
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'لا يوجد سعر للساعة لهذا الموقف',
                        'error_type' => 'price_not_found',
                        'status_code' => 400
                    ], 400);
                }
            }
            // إذا كانت المدة يوم أو أكثر ويوجد سعر يوم
            elseif ($totalDays >= 1 && $parkingSpot->price_per_day) {
                $fullDays = floor($totalDays);
                $remainingMinutes = $totalMinutes - ($fullDays * 24 * 60);
                $remainingHours = $remainingMinutes / 60;
                
                // حساب سعر الأيام الكاملة
                $dayCost = $fullDays * $parkingSpot->price_per_day;
                $totalAmount = $dayCost;
                $priceBreakdown['days'] = [
                    'method' => 'حساب بالأيام',
                    'days' => $fullDays,
                    'price_per_day' => $parkingSpot->price_per_day,
                    'total' => $dayCost
                ];
                
                // حساب الساعات المتبقية (مع الكسور)
                if ($remainingMinutes > 0 && $parkingSpot->price_per_hour) {
                    $hourCost = $remainingHours * $parkingSpot->price_per_hour;
                    $totalAmount += $hourCost;
                    $priceBreakdown['hours'] = [
                        'method' => 'حساب بالساعات المتبقية (مع الكسور)',
                        'hours' => round($remainingHours, 2),
                        'minutes' => $remainingMinutes,
                        'price_per_hour' => $parkingSpot->price_per_hour,
                        'total' => round($hourCost, 2)
                    ];
                }
                $calculationMethod = 'per_day_with_hours';
            }
            // إذا كانت المدة يوم أو أكثر ولا يوجد سعر يوم (استخدم الساعة)
            elseif ($totalDays >= 1 && !$parkingSpot->price_per_day && $parkingSpot->price_per_hour) {
                $totalAmount = $totalHours * $parkingSpot->price_per_hour;
                $calculationMethod = 'per_hour';
                $priceBreakdown = [
                    'method' => 'حساب بالساعة (لأنه لا يوجد سعر يوم)',
                    'hours' => round($totalHours, 2),
                    'minutes' => $totalMinutes,
                    'price_per_hour' => $parkingSpot->price_per_hour,
                    'total' => round($totalAmount, 2)
                ];
            }
            // إذا كانت المدة شهر أو أكثر (30 يوم)
            elseif ($totalDays >= 30 && $parkingSpot->price_per_month) {
                $months = floor($totalDays / 30);
                $remainingDays = $totalDays - ($months * 30);
                $remainingMinutes = $remainingDays * 24 * 60;
                
                // حساب سعر الأشهر
                $monthCost = $months * $parkingSpot->price_per_month;
                $totalAmount = $monthCost;
                $priceBreakdown['months'] = [
                    'method' => 'حساب بالأشهر',
                    'months' => $months,
                    'price_per_month' => $parkingSpot->price_per_month,
                    'total' => $monthCost
                ];
                
                // حساب الأيام المتبقية (مع الكسور)
                if ($remainingDays > 0 && $parkingSpot->price_per_day) {
                    $dayCost = $remainingDays * $parkingSpot->price_per_day;
                    $totalAmount += $dayCost;
                    $priceBreakdown['remaining_days'] = [
                        'method' => 'حساب بالأيام المتبقية',
                        'days' => round($remainingDays, 2),
                        'price_per_day' => $parkingSpot->price_per_day,
                        'total' => round($dayCost, 2)
                    ];
                } elseif ($remainingMinutes > 0 && $parkingSpot->price_per_hour) {
                    $remainingHours = $remainingMinutes / 60;
                    $hourCost = $remainingHours * $parkingSpot->price_per_hour;
                    $totalAmount += $hourCost;
                    $priceBreakdown['remaining_hours'] = [
                        'method' => 'حساب بالساعات المتبقية (مع الكسور)',
                        'hours' => round($remainingHours, 2),
                        'minutes' => $remainingMinutes,
                        'price_per_hour' => $parkingSpot->price_per_hour,
                        'total' => round($hourCost, 2)
                    ];
                }
                $calculationMethod = 'per_month_with_remaining';
            }
            // إذا كانت المدة شهر أو أكثر ولا يوجد سعر شهر (استخدم اليوم)
            elseif ($totalDays >= 30 && !$parkingSpot->price_per_month && $parkingSpot->price_per_day) {
                $dayCost = $totalDays * $parkingSpot->price_per_day;
                $totalAmount = $dayCost;
                $priceBreakdown = [
                    'method' => 'حساب بالأيام (لأنه لا يوجد سعر شهر)',
                    'days' => round($totalDays, 2),
                    'price_per_day' => $parkingSpot->price_per_day,
                    'total' => round($dayCost, 2)
                ];
                $calculationMethod = 'per_day_only';
            }
            else {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد سعر محدد لهذه الفترة (ساعة، يوم، أو شهر)',
                    'error_type' => 'price_not_found',
                    'status_code' => 400
                ], 400);
            }

            // تقريب المبلغ إلى منزلتين عشريتين
            $totalAmount = round($totalAmount, 2);

            if ($totalAmount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'المبلغ المحسوب غير صحيح',
                    'error_type' => 'invalid_amount',
                    'status_code' => 400
                ], 400);
            }

            // 7. تنسيق مدة الحجز بالكلمات
            $durationText = $this->formatDuration($startTime, $endTime);

            // 8. إنشاء الحجز (status = pending)
            // ملاحظة: user_id هنا هو المستأجر (الذي يقوم بالحجز)
            $booking = Booking::create([
                'spot_id' => $request->spot_id,
                'user_id' => $user->id, // هذا هو المستأجر (الرينتر)
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'totale_amount' => $totalAmount,
                'status' => 'pending',
                'payment_intent_id' => null,
            ]);

            $booking->load('parkingSpot', 'user');

            Log::info('تم إنشاء حجز جديد', [
                'renter_id' => $user->id, // المستأجر
                'owner_id' => $parkingSpot->user_id, // صاحب الموقف
                'booking_id' => $booking->id,
                'spot_id' => $parkingSpot->id,
                'total_amount' => $totalAmount,
                'total_minutes' => $totalMinutes,
                'calculation_method' => $calculationMethod,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء الحجز بنجاح، في انتظار الدفع',
                'data' => [
                    'booking' => $booking,
                    'parking_spot' => [
                        'id' => $parkingSpot->id,
                        'title' => $parkingSpot->title,
                        'address' => $parkingSpot->address,
                        'capacity' => $parkingSpot->capacity,
                        'available_spots' => $parkingSpot->capacity - ($activeCount + 1),
                        'owner' => [ // صاحب الموقف
                            'id' => $parkingSpot->user_id,
                            'name' => $parkingSpot->user->name ?? null,
                        ]
                    ],
                    'renter' => [ // المستأجر
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                    ],
                    'duration' => [
                        'start_time' => $startTime->toDateTimeString(),
                        'end_time' => $endTime->toDateTimeString(),
                        'total_hours' => round($totalHours, 2),
                        'total_minutes' => $totalMinutes,
                        'total_days' => round($totalDays, 2),
                        'text' => $durationText,
                    ],
                    'price_breakdown' => $priceBreakdown,
                    'total_amount' => $totalAmount,
                    'status' => 'pending',
                    'status_text' => 'في انتظار الدفع',
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
            Log::error('خطأ في قاعدة البيانات أثناء الحجز', [
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
            Log::error('خطأ في إنشاء الحجز', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إنشاء الحجز',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }

    /**
     * تنسيق مدة الحجز إلى نص
     */
    private function formatDuration($startTime, $endTime)
    {
        $diff = $startTime->diff($endTime);
        
        $parts = [];
        if ($diff->y > 0) $parts[] = $diff->y . ' سنة';
        if ($diff->m > 0) $parts[] = $diff->m . ' شهر';
        if ($diff->d > 0) $parts[] = $diff->d . ' يوم';
        if ($diff->h > 0) $parts[] = $diff->h . ' ساعة';
        if ($diff->i > 0) $parts[] = $diff->i . ' دقيقة';
        
        return implode(' و ', $parts) ?: 'أقل من دقيقة';
    }

    /**
     * عرض تفاصيل الحجز
     */
    public function showBooking($id)
    {
        try {
            $user = request()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك',
                    'error_type' => 'unauthenticated',
                    'status_code' => 401
                ], 401);
            }

            $booking = Booking::with(['parkingSpot', 'parkingSpot.images', 'parkingSpot.user', 'user'])
                ->find($id);

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'الحجز غير موجود',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // التحقق من الصلاحية
            $userRole = Role::where('user_id', $user->id)->first();
            $isOwner = $booking->parkingSpot->user_id == $user->id;
            $isRenter = $booking->user_id == $user->id;
            $isAdmin = $userRole && $userRole->role == 1;

            if (!$isOwner && !$isRenter && !$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك بمشاهدة هذا الحجز',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب تفاصيل الحجز بنجاح',
                'data' => $booking
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
     * عرض حجوزات المستخدم الحالي (المستأجر)
     */
    public function myBookings(Request $request)
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

            $bookings = Booking::where('user_id', $user->id)
                ->with(['parkingSpot', 'parkingSpot.images', 'parkingSpot.user'])
                ->orderBy('created_at', 'desc')
                ->get();

            $stats = [
                'total' => $bookings->count(),
                'pending' => $bookings->where('status', 'pending')->count(),
                'paid' => $bookings->where('status', 'paid')->count(),
                'active' => $bookings->where('status', 'active')->count(),
                'completed' => $bookings->where('status', 'completed')->count(),
                'cancelled' => $bookings->where('status', 'cancelled')->count(),
                'expired' => $bookings->where('status', 'expired')->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'تم جلب حجوزاتك بنجاح',
                'data' => $bookings,
                'stats' => $stats,
                'count' => $bookings->count()
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
     * عرض حجوزات موقف معين (لصاحب الموقف)
     */
    public function spotBookings(Request $request, $spotId)
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

            $parkingSpot = Parking_spot::find($spotId);
            
            if (!$parkingSpot) {
                return response()->json([
                    'success' => false,
                    'message' => 'الموقف غير موجود',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // التحقق من أن المستخدم هو صاحب الموقف
            if ($parkingSpot->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط صاحب الموقف يمكنه عرض حجوزاته',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            $bookings = Booking::where('spot_id', $spotId)
                ->with(['user'])
                ->orderBy('created_at', 'desc')
                ->get();

            $stats = [
                'total' => $bookings->count(),
                'pending' => $bookings->where('status', 'pending')->count(),
                'paid' => $bookings->where('status', 'paid')->count(),
                'active' => $bookings->where('status', 'active')->count(),
                'completed' => $bookings->where('status', 'completed')->count(),
                'cancelled' => $bookings->where('status', 'cancelled')->count(),
                'expired' => $bookings->where('status', 'expired')->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'تم جلب حجوزات الموقف بنجاح',
                'data' => [
                    'parking_spot' => [
                        'id' => $parkingSpot->id,
                        'title' => $parkingSpot->title,
                        'address' => $parkingSpot->address,
                    ],
                    'bookings' => $bookings,
                    'stats' => $stats,
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
     * تحديث حالة الحجز (للمالك أو المدير)
     */
    public function updateBookingStatus(Request $request, $id)
    {
        try {
            $user = request()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك',
                    'error_type' => 'unauthenticated',
                    'status_code' => 401
                ], 401);
            }

            $booking = Booking::with('parkingSpot')->find($id);

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'الحجز غير موجود',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // التحقق من الصلاحية (المالك أو المدير)
            $userRole = Role::where('user_id', $user->id)->first();
            $isOwner = $booking->parkingSpot->user_id == $user->id;
            $isAdmin = $userRole && $userRole->role == 1;

            if (!$isOwner && !$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط المالك أو المدير يمكنه تحديث حالة الحجز',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            $request->validate([
                'status' => 'required|in:pending,paid,active,completed,cancelled,expired',
            ]);

            $oldStatus = $booking->status;
            $booking->status = $request->status;
            $booking->save();

            Log::info('تم تحديث حالة الحجز', [
                'user_id' => $user->id,
                'booking_id' => $booking->id,
                'old_status' => $oldStatus,
                'new_status' => $request->status,
                'ip' => request()->ip()
            ]);

            $statusTexts = [
                'pending' => 'في انتظار الدفع',
                'paid' => 'تم الدفع',
                'active' => 'نشط',
                'completed' => 'مكتمل',
                'cancelled' => 'ملغي',
                'expired' => 'منتهي',
            ];

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث حالة الحجز بنجاح',
                'data' => [
                    'booking' => $booking,
                    'old_status' => $oldStatus,
                    'new_status' => $request->status,
                    'status_text' => $statusTexts[$request->status] ?? $request->status,
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






/////////////////////////////


/**
 * طلب إلغاء الحجز (من المستأجر)
 * الشرط: قبل وقت الحجز بساعتين على الأقل
 */
public function requestCancellation(Request $request, $id)
{
    try {
        // ============================================
        // 1. التحقق من المستخدم
        // ============================================
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success'     => false,
                'message'     => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                'error_type'  => 'unauthenticated',
                'status_code' => 401,
            ], 401);
        }

        // ============================================
        // 2. التحقق من صحة البيانات
        // ============================================
        $request->validate([
            'cancellation_reason' => 'required|string|max:500',
        ], [
            'cancellation_reason.required' => 'سبب الإلغاء مطلوب',
            'cancellation_reason.string'   => 'سبب الإلغاء يجب أن يكون نصاً',
            'cancellation_reason.max'      => 'سبب الإلغاء لا يتجاوز 500 حرف',
        ]);

        // ============================================
        // 3. البحث عن الحجز
        // ============================================
        $booking = Booking::with(['parkingSpot', 'user'])->find($id);

        if (!$booking) {
            return response()->json([
                'success'     => false,
                'message'     => 'الحجز غير موجود',
                'error_type'  => 'not_found',
                'status_code' => 404,
            ], 404);
        }

        // ============================================
        // 4. التحقق من أن المستخدم هو صاحب الحجز
        // ============================================
        if ($booking->user_id != $user->id) {
            return response()->json([
                'success'     => false,
                'message'     => 'غير مصرح لك، هذا الحجز ليس لك',
                'error_type'  => 'unauthorized',
                'status_code' => 403,
            ], 403);
        }

        // ============================================
        // 5. التحقق من حالة الحجز
        // ============================================
        $allowedStatuses = ['pending', 'paid'];

        if (!in_array($booking->status, $allowedStatuses)) {
            return response()->json([
                'success'     => false,
                'message'     => 'لا يمكن إلغاء الحجز في حالته الحالية: ' . $booking->status,
                'error_type'  => 'invalid_booking_status',
                'status_code' => 400,
                'data'        => [
                    'current_status' => $booking->status,
                ],
            ], 400);
        }

        // ============================================
        // 6. التحقق: هل تم طلب إلغاء مسبقاً؟
        // ============================================
        $existingCancellation = BookingCancellation::where('booking_id', $booking->id)
            ->where('decision', BookingCancellation::DECISION_PENDING)
            ->first();

        if ($existingCancellation) {
            return response()->json([
                'success'     => false,
                'message'     => 'تم طلب إلغاء هذا الحجز مسبقاً، في انتظار رد صاحب الموقف',
                'error_type'  => 'cancellation_already_requested',
                'status_code' => 400,
                'data'        => [
                    'cancellation' => $existingCancellation,
                ],
            ], 400);
        }

        // ============================================
        // 7. الشرط الأساسي: قبل وقت الحجز بساعتين على الأقل
        // ============================================
        $startTime = \Carbon\Carbon::parse($booking->start_time);
        $now = \Carbon\Carbon::now();
        $hoursUntilStart = $now->diffInHours($startTime, false);

        if ($hoursUntilStart < 2) {
            return response()->json([
                'success'     => false,
                'message'     => 'لا يمكن إلغاء الحجز، يجب أن يكون الإلغاء قبل وقت الحجز بساعتين على الأقل',
                'error_type'  => 'cancellation_deadline_passed',
                'status_code' => 400,
                'data'        => [
                    'start_time'        => $startTime->toDateTimeString(),
                    'current_time'      => $now->toDateTimeString(),
                    'hours_until_start' => round($hoursUntilStart, 2),
                    'minimum_hours'     => 2,
                ],
            ], 400);
        }

        // ============================================
        // 8. إنشاء طلب الإلغاء
        // ============================================
        $cancellation = BookingCancellation::create([
            'booking_id'                 => $booking->id,
            'requested_by'               => $user->id,
            'cancellation_reason'        => $request->cancellation_reason,
            'cancellation_requested_at'  => now(),
            'decision'                   => BookingCancellation::DECISION_PENDING,
        ]);

        // ============================================
        // 9. إرسال إشعار لصاحب الموقف
        // ============================================
        try {
            $owner = User::find($booking->parkingSpot->user_id);

            if ($owner) {
                $owner->notify(new \App\Notifications\BookingCancellationNotification(
                    $booking,
                    'cancellation_requested',
                    '📩 طلب إلغاء حجز',
                    "قام {$user->name} بطلب إلغاء حجز في موقفك: {$booking->parkingSpot->title}"
                ));
            }
        } catch (\Exception $e) {
            Log::warning('فشل إرسال إشعار لصاحب الموقف', [
                'error'      => $e->getMessage(),
                'booking_id' => $booking->id,
            ]);
        }

        // ============================================
        // 10. تسجيل العملية
        // ============================================
        Log::info('تم طلب إلغاء الحجز', [
            'booking_id'          => $booking->id,
            'cancellation_id'     => $cancellation->id,
            'user_id'             => $user->id,
            'reason'              => $request->cancellation_reason,
            'hours_until_start'   => round($hoursUntilStart, 2),
            'ip'                  => $request->ip(),
        ]);

        // ============================================
        // 11. إرجاع الاستجابة
        // ============================================
        return response()->json([
            'success' => true,
            'message' => 'تم طلب إلغاء الحجز بنجاح، في انتظار رد صاحب الموقف',
            'data'    => [
                'cancellation'      => $cancellation,
                'booking'           => $booking,
                'hours_until_start' => round($hoursUntilStart, 2),
            ],
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'success'     => false,
            'message'     => 'فشل التحقق من البيانات',
            'errors'      => $e->errors(),
            'error_type'  => 'validation_error',
            'status_code' => 422,
        ], 422);

    } catch (\Exception $e) {
        Log::error('Error in request cancellation', ['error' => $e->getMessage()]);

        return response()->json([
            'success'     => false,
            'message'     => 'حدث خطأ أثناء طلب إلغاء الحجز',
            'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type'  => 'general_error',
            'status_code' => 500,
        ], 500);
    }
}



////////////////
/**
 * الموافقة أو الرفض على إلغاء الحجز (من صاحب الموقف)
 */
public function respondToCancellation(Request $request, $id)
{
    try {
        // ============================================
        // 1. التحقق من المستخدم
        // ============================================
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success'     => false,
                'message'     => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                'error_type'  => 'unauthenticated',
                'status_code' => 401,
            ], 401);
        }

        // ============================================
        // 2. التحقق من صحة البيانات
        // ============================================
        $request->validate([
            'decision' => 'required|in:approve,reject',
            'response' => 'nullable|string|max:500',
        ], [
            'decision.required' => 'القرار مطلوب',
            'decision.in'       => 'القرار يجب أن يكون: approve أو reject',
            'response.string'   => 'الرد يجب أن يكون نصاً',
            'response.max'      => 'الرد لا يتجاوز 500 حرف',
        ]);

        // ============================================
        // 3. البحث عن الحجز
        // ============================================
        $booking = Booking::with(['parkingSpot', 'user'])->find($id);

        if (!$booking) {
            return response()->json([
                'success'     => false,
                'message'     => 'الحجز غير موجود',
                'error_type'  => 'not_found',
                'status_code' => 404,
            ], 404);
        }

        // ============================================
        // 4. التحقق من أن المستخدم هو صاحب الموقف
        // ============================================
        if ($booking->parkingSpot->user_id != $user->id) {
            return response()->json([
                'success'     => false,
                'message'     => 'غير مصرح لك، هذا الموقف ليس ملكك',
                'error_type'  => 'unauthorized',
                'status_code' => 403,
            ], 403);
        }

        // ============================================
        // 5. البحث عن طلب الإلغاء
        // ============================================
        $cancellation = BookingCancellation::where('booking_id', $booking->id)
            ->where('decision', BookingCancellation::DECISION_PENDING)
            ->first();

        if (!$cancellation) {
            return response()->json([
                'success'     => false,
                'message'     => 'لا يوجد طلب إلغاء معلق لهذا الحجز',
                'error_type'  => 'no_pending_cancellation',
                'status_code' => 400,
            ], 400);
        }

        // ============================================
        // 6. تحديد القرار
        // ============================================
        $decision = $request->decision === 'approve'
            ? BookingCancellation::DECISION_APPROVED
            : BookingCancellation::DECISION_REJECTED;

        // ============================================
        // 7. تحديث طلب الإلغاء
        // ============================================
        $cancellation->update([
            'decision'                   => $decision,
            'responded_by'               => $user->id,
            'cancellation_response'      => $request->response,
            'cancellation_responded_at'  => now(),
        ]);

        // ============================================
        // 8. تحديث حالة الحجز
        // ============================================
        if ($decision === BookingCancellation::DECISION_APPROVED) {
            $booking->update([
                'status' => 'cancelled',
            ]);
        }
        // إذا رُفض: الحجز يبقى كما هو (paid أو pending)

        // ============================================
        // 9. إرسال إشعار للمستأجر
        // ============================================
        try {
            $renter = User::find($booking->user_id);

            if ($renter) {
                $notificationTitle = $decision === BookingCancellation::DECISION_APPROVED
                    ? '✅ تمت الموافقة على إلغاء الحجز'
                    : '❌ تم رفض طلب إلغاء الحجز';

                $notificationBody = $decision === BookingCancellation::DECISION_APPROVED
                    ? "تمت الموافقة على إلغاء حجزك في: {$booking->parkingSpot->title}"
                    : "تم رفض طلب إلغاء حجزك في: {$booking->parkingSpot->title}";

                $renter->notify(new \App\Notifications\BookingCancellationNotification(
                    $booking,
                    'cancellation_' . $request->decision . 'd',
                    $notificationTitle,
                    $notificationBody
                ));
            }
        } catch (\Exception $e) {
            Log::warning('فشل إرسال إشعار للمستأجر', [
                'error'      => $e->getMessage(),
                'booking_id' => $booking->id,
            ]);
        }

        // ============================================
        // 10. تسجيل العملية
        // ============================================
        Log::info('تم الرد على طلب إلغاء الحجز', [
            'booking_id'       => $booking->id,
            'cancellation_id'  => $cancellation->id,
            'owner_id'         => $user->id,
            'decision'         => $decision,
            'booking_status'   => $booking->status,
            'response'         => $request->response,
            'ip'               => $request->ip(),
        ]);

        // ============================================
        // 11. تحديد الرسالة
        // ============================================
        $message = $decision === BookingCancellation::DECISION_APPROVED
            ? 'تمت الموافقة على إلغاء الحجز بنجاح'
            : 'تم رفض طلب إلغاء الحجز';

        // ============================================
        // 12. إرجاع الاستجابة
        // ============================================
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => [
                'cancellation'    => $cancellation,
                'booking'         => $booking,
                'decision'        => $decision,
                'decision_text'   => $cancellation->decision_text,
                'booking_status'  => $booking->status,
            ],
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'success'     => false,
            'message'     => 'فشل التحقق من البيانات',
            'errors'      => $e->errors(),
            'error_type'  => 'validation_error',
            'status_code' => 422,
        ], 422);

    } catch (\Exception $e) {
        Log::error('Error in respond to cancellation', ['error' => $e->getMessage()]);

        return response()->json([
            'success'     => false,
            'message'     => 'حدث خطأ أثناء الرد على طلب الإلغاء',
            'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type'  => 'general_error',
            'status_code' => 500,
        ], 500);
    }
}


}