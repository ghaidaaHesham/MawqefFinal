<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Complaint;
use App\Models\ComplaintImage;
use App\Models\User;
use App\Models\Role;
use App\Notifications\ComplaintNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class ComplaintController extends Controller
{
    /**
     * تقديم شكوى جديدة
     * - الرينتر يشتكي على الأونر
     * - الأونر يشتكي على الرينتر
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'غير مصرح لك، يرجى تسجيل الدخول أولاً',
                    'error_type'  => 'unauthenticated',
                    'status_code' => 401,
                ], 401);
            }

            // ===== التحقق من البيانات =====
            $request->validate([
                'complained_against_id' => 'required|exists:users,id',
                'subject'               => 'required|string|max:191',
                'message'               => 'required|string|max:2000',
                'booking_id'            => 'nullable|exists:bookings,id',
                'parking_id'            => 'nullable|exists:parking_spots,id',
                'images'                => 'nullable|array',
                'images.*'              => 'image|mimes:jpeg,png,jpg,webp|max:2048',
            ], [
                'complained_against_id.required' => 'يجب تحديد المستخدم الذي تريد تقديم شكوى ضده',
                'complained_against_id.exists'   => 'المستخدم غير موجود',
                'subject.required'               => 'عنوان الشكوى مطلوب',
                'subject.max'                    => 'العنوان لا يتجاوز 191 حرف',
                'message.required'               => 'رسالة الشكوى مطلوبة',
                'message.max'                    => 'الرسالة لا تتجاوز 2000 حرف',
                'booking_id.exists'              => 'الحجز غير موجود',
                'parking_id.exists'              => 'الموقف غير موجود',
                'images.array'                   => 'الصور يجب أن تكون مصفوفة',
                'images.*.image'                 => 'يجب أن تكون الصور بصيغة صورة',
                'images.*.mimes'                 => 'الصور يجب أن تكون بصيغة: jpeg, png, jpg, webp',
                'images.*.max'                   => 'حجم الصورة يجب أن لا يتجاوز 2MB',
            ]);

            // ===== التحقق: لا يمكن الشكوى على النفس =====
            if ($user->id == $request->complained_against_id) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'لا يمكنك تقديم شكوى على نفسك',
                    'error_type'  => 'self_complaint',
                    'status_code' => 400,
                ], 400);
            }

            // ===== التحقق: المشتكى عليه موجود =====
            $complainedAgainst = User::find($request->complained_against_id);

            if (!$complainedAgainst) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'المستخدم المشتكى عليه غير موجود',
                    'error_type'  => 'complained_against_not_found',
                    'status_code' => 404,
                ], 404);
            }

            // ===== بدء Transaction =====
            DB::beginTransaction();

            try {
                // 1. إنشاء الشكوى
                $complaint = Complaint::create([
                    'complainant_id'        => $user->id,
                    'complained_against_id' => $request->complained_against_id,
                    'booking_id'            => $request->booking_id,
                    'parking_id'            => $request->parking_id,
                    'subject'               => $request->subject,
                    'message'               => $request->message,
                    'status'                => Complaint::STATUS_PENDING,
                ]);

                // 2. رفع الصور (إذا وجدت)
                $uploadedImages = [];

                if ($request->hasFile('images')) {
                    foreach ($request->file('images') as $image) {
                        if ($image && $image->isValid()) {
                            $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                            $path = $image->storeAs('complaints', $imageName, 'public');

                            $uploadedImages[] = ComplaintImage::create([
                                'path'           => $path,
                                'title'          => $complaint->subject . '_' . time(),
                                'imageable_type' => Complaint::class,
                                'imageable_id'   => $complaint->id,
                            ]);
                        }
                    }
                }

                // 3. إرسال إشعار للإدمن
                $admins = User::whereHas('role', function ($q) {
                    $q->where('role', 1);
                })->get();

                foreach ($admins as $admin) {
                    $admin->notify(new ComplaintNotification(
                        $complaint,
                        'new_complaint',
                        '📩 شكوى جديدة',
                        "تم تقديم شكوى جديدة من {$user->name} ضد {$complainedAgainst->name}"
                    ));
                }

                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            // ===== تحميل العلاقات =====
            $complaint->load(['complainant', 'complainedAgainst', 'booking', 'parking', 'images']);

            // ===== تسجيل =====
            Log::info('تم تقديم شكوى جديدة', [
                'complaint_id'  => $complaint->id,
                'complainant'   => $user->id,
                'against'       => $request->complained_against_id,
                'has_images'    => count($uploadedImages),
                'ip'            => $request->ip(),
            ]);

            // ===== إرجاع الاستجابة =====
            return response()->json([
                'success' => true,
                'message' => 'تم تقديم الشكوى بنجاح، سيتم مراجعتها من قبل الإدارة',
                'data'    => [
                    'complaint'      => $complaint,
                    'images'         => $uploadedImages,
                    'images_count'   => count($uploadedImages),
                ],
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'فشل التحقق من البيانات',
                'errors'      => $e->errors(),
                'error_type'  => 'validation_error',
                'status_code' => 422,
            ], 422);

        } catch (QueryException $e) {
            Log::error('DB error in complaint store', ['error' => $e->getMessage()]);
            return response()->json([
                'success'     => false,
                'message'     => 'حدث خطأ في قاعدة البيانات',
                'error_type'  => 'database_error',
                'status_code' => 500,
            ], 500);

        } catch (\Exception $e) {
            Log::error('Error in complaint store', ['error' => $e->getMessage()]);
            return response()->json([
                'success'     => false,
                'message'     => 'حدث خطأ أثناء تقديم الشكوى',
                'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type'  => 'general_error',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * عرض شكاوى المستخدم الحالي
     */
    public function myComplaints(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'غير مصرح لك',
                    'error_type'  => 'unauthenticated',
                    'status_code' => 401,
                ], 401);
            }

            // الشكاوى التي قدّمها
            $submitted = Complaint::where('complainant_id', $user->id)
                ->with(['complainedAgainst', 'booking', 'parking', 'images', 'responder'])
                ->orderBy('created_at', 'desc')
                ->get();

            // الشكاوى المقدمة ضده
            $received = Complaint::where('complained_against_id', $user->id)
                ->with(['complainant', 'booking', 'parking', 'images', 'responder'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الشكاوى بنجاح',
                'data'    => [
                    'submitted' => $submitted,
                    'received'  => $received,
                    'counts'    => [
                        'submitted' => $submitted->count(),
                        'received'  => $received->count(),
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'حدث خطأ',
                'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * عرض جميع الشكاوى (للإدمن فقط)
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $userRole = Role::where('user_id', $user->id)->first();

            if (!$userRole || $userRole->role != 1) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'غير مصرح لك، فقط الإدمن يمكنه عرض جميع الشكاوى',
                    'error_type'  => 'unauthorized',
                    'status_code' => 403,
                ], 403);
            }

            $query = Complaint::with([
                'complainant',
                'complainedAgainst',
                'booking',
                'parking',
                'images',
                'responder',
            ]);

            // فلترة حسب الحالة
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // ترتيب
            $query->orderBy('created_at', 'desc');

            $perPage = $request->get('per_page', 15);
            $complaints = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الشكاوى بنجاح',
                'data'    => $complaints,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'حدث خطأ',
                'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * عرض تفاصيل شكوى محددة
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'غير مصرح لك',
                    'error_type'  => 'unauthenticated',
                    'status_code' => 401,
                ], 401);
            }

            $complaint = Complaint::with([
                'complainant',
                'complainedAgainst',
                'booking',
                'parking',
                'images',
                'responder',
            ])->find($id);

            if (!$complaint) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'الشكوى غير موجودة',
                    'error_type'  => 'not_found',
                    'status_code' => 404,
                ], 404);
            }

            // ===== التحقق من الصلاحية =====
            $userRole = Role::where('user_id', $user->id)->first();
            $isAdmin = $userRole && $userRole->role == 1;
            $isComplainant = $complaint->complainant_id == $user->id;
            $isAgainst = $complaint->complained_against_id == $user->id;

            if (!$isAdmin && !$isComplainant && !$isAgainst) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'غير مصرح لك بمشاهدة هذه الشكوى',
                    'error_type'  => 'unauthorized',
                    'status_code' => 403,
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الشكوى بنجاح',
                'data'    => $complaint,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'حدث خطأ',
                'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * رد الإدمن على الشكوى (للإدمن فقط)
     */
    public function adminRespond(Request $request, $id)
    {
        try {
            $user = $request->user();
            $userRole = Role::where('user_id', $user->id)->first();

            if (!$userRole || $userRole->role != 1) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'غير مصرح لك، فقط الإدمن يمكنه الرد على الشكاوى',
                    'error_type'  => 'unauthorized',
                    'status_code' => 403,
                ], 403);
            }

            // ===== التحقق من البيانات =====
            $request->validate([
                'admin_response' => 'required|string|max:2000',
                'status'         => 'required|in:pending,in_review,resolved,rejected',
            ], [
                'admin_response.required' => 'رد الإدمن مطلوب',
                'admin_response.max'      => 'الرد لا يتجاوز 2000 حرف',
                'status.required'         => 'حالة الشكوى مطلوبة',
                'status.in'               => 'الحالة يجب أن تكون: pending, in_review, resolved, rejected',
            ]);

            $complaint = Complaint::with(['complainant', 'complainedAgainst'])->find($id);

            if (!$complaint) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'الشكوى غير موجودة',
                    'error_type'  => 'not_found',
                    'status_code' => 404,
                ], 404);
            }

            // ===== تحديث الشكوى =====
            $complaint->update([
                'admin_response' => $request->admin_response,
                'status'         => $request->status,
                'responded_by'   => $user->id,
                'responded_at'   => now(),
            ]);

            // ===== إرسال إشعارات للطرفين =====
            $complaint->load(['complainant', 'complainedAgainst', 'responder']);

            $notificationTitle = match ($request->status) {
                'resolved'  => '✅ تم حل الشكوى',
                'rejected'  => '❌ تم رفض الشكوى',
                'in_review' => '🔍 الشكوى قيد المراجعة',
                default     => '📋 تحديث على الشكوى',
            };

            $notificationBody = "تم الرد على شكواك: {$complaint->subject}";

            // إشعار لمقدّم الشكوى
            $complaint->complainant->notify(new ComplaintNotification(
                $complaint,
                'admin_reply',
                $notificationTitle,
                $notificationBody
            ));

            // إشعار للمشتكى عليه
            $complaint->complainedAgainst->notify(new ComplaintNotification(
                $complaint,
                'admin_reply',
                $notificationTitle,
                "تم الرد على الشكوى المقدمة ضدك: {$complaint->subject}"
            ));

            // ===== تسجيل =====
            Log::info('تم رد الإدمن على الشكوى', [
                'complaint_id' => $complaint->id,
                'admin_id'     => $user->id,
                'status'       => $request->status,
                'ip'           => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم الرد على الشكوى بنجاح، وتم إرسال إشعارات للطرفين',
                'data'    => $complaint,
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
            Log::error('Error in admin respond', ['error' => $e->getMessage()]);
            return response()->json([
                'success'     => false,
                'message'     => 'حدث خطأ أثناء الرد على الشكوى',
                'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * عرض الإشعارات للمستخدم الحالي
     */
    public function myNotifications(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'غير مصرح لك',
                    'error_type'  => 'unauthenticated',
                    'status_code' => 401,
                ], 401);
            }

            $notifications = $user->notifications()
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الإشعارات بنجاح',
                'data'    => $notifications,
                'unread_count' => $user->unreadNotifications()->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'حدث خطأ',
                'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * تعليم الإشعارات كمقروءة
     */
    public function markAsRead(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'غير مصرح لك',
                    'error_type'  => 'unauthenticated',
                    'status_code' => 401,
                ], 401);
            }

            $user->unreadNotifications->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'تم تعليم الإشعارات كمقروءة',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'حدث خطأ',
                'status_code' => 500,
            ], 500);
        }
    }













    /**
 * تغيير حالة الشكوى (للإدمن فقط)
 * لا يتضمن رد، فقط تغيير الحالة
 */
public function updateStatus(Request $request, $id)
{
    try {
        // ============================================
        // 1. التحقق من أن المستخدم إدمن
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

        $userRole = Role::where('user_id', $user->id)->first();

        if (!$userRole || $userRole->role != 1) {
            return response()->json([
                'success'     => false,
                'message'     => 'غير مصرح لك، فقط الإدمن يمكنه تغيير حالة الشكوى',
                'error_type'  => 'unauthorized',
                'status_code' => 403,
            ], 403);
        }

        // ============================================
        // 2. التحقق من صحة البيانات
        // ============================================
        $request->validate([
            'status' => 'required|in:pending,in_review,resolved,rejected',
        ], [
            'status.required' => 'حالة الشكوى مطلوبة',
            'status.in'       => 'الحالة يجب أن تكون: pending, in_review, resolved, rejected',
        ]);

        // ============================================
        // 3. البحث عن الشكوى
        // ============================================
        $complaint = Complaint::with([
            'complainant',
            'complainedAgainst',
        ])->find($id);

        if (!$complaint) {
            return response()->json([
                'success'     => false,
                'message'     => 'الشكوى غير موجودة',
                'error_type'  => 'not_found',
                'status_code' => 404,
            ], 404);
        }

        // ============================================
        // 4. التحقق من أن الحالة الجديدة مختلفة عن القديمة
        // ============================================
        if ($complaint->status === $request->status) {
            return response()->json([
                'success'     => false,
                'message'     => 'الحالة الجديدة هي نفس الحالة الحالية',
                'error_type'  => 'same_status',
                'status_code' => 400,
                'data'        => [
                    'current_status' => $complaint->status,
                    'current_status_text' => $complaint->status_text,
                ],
            ], 400);
        }

        // ============================================
        // 5. حفظ الحالة القديمة
        // ============================================
        $oldStatus = $complaint->status;
        $oldStatusText = $complaint->status_text;

        // ============================================
        // 6. تحديث الحالة
        // ============================================
        $complaint->update([
            'status' => $request->status,
        ]);

        // ============================================
        // 7. تحميل العلاقات
        // ============================================
        $complaint->load(['complainant', 'complainedAgainst', 'responder']);

        // ============================================
        // 8. تجهيز الإشعار
        // ============================================
        $notificationTitle = match ($request->status) {
            'pending'   => '⏳ الشكوى قيد الانتظار',
            'in_review' => '🔍 الشكوى قيد المراجعة',
            'resolved'  => '✅ تم حل الشكوى',
            'rejected'  => '❌ تم رفض الشكوى',
            default     => '📋 تحديث على الشكوى',
        };

        $notificationBody = "تم تحديث حالة الشكوى: {$complaint->subject} إلى " . $complaint->status_text;

        // ============================================
        // 9. إرسال إشعار لمقدّم الشكوى
        // ============================================
        try {
            $complaint->complainant->notify(new ComplaintNotification(
                $complaint,
                'status_updated',
                $notificationTitle,
                $notificationBody
            ));
        } catch (\Exception $e) {
            Log::warning('فشل إرسال إشعار لمقدّم الشكوى', [
                'error'        => $e->getMessage(),
                'complaint_id' => $complaint->id,
            ]);
        }

        // ============================================
        // 10. إرسال إشعار للمشتكى عليه
        // ============================================
        try {
            $complaint->complainedAgainst->notify(new ComplaintNotification(
                $complaint,
                'status_updated',
                $notificationTitle,
                "تم تحديث حالة الشكوى المقدمة ضدك: {$complaint->subject} إلى " . $complaint->status_text
            ));
        } catch (\Exception $e) {
            Log::warning('فشل إرسال إشعار للمشتكى عليه', [
                'error'        => $e->getMessage(),
                'complaint_id' => $complaint->id,
            ]);
        }

        // ============================================
        // 11. تسجيل العملية
        // ============================================
        Log::info('تم تغيير حالة الشكوى', [
            'complaint_id'      => $complaint->id,
            'admin_id'          => $user->id,
            'admin_name'        => $user->name,
            'old_status'        => $oldStatus,
            'old_status_text'   => $oldStatusText,
            'new_status'        => $complaint->status,
            'new_status_text'   => $complaint->status_text,
            'ip'                => $request->ip(),
        ]);

        // ============================================
        // 12. إرجاع الاستجابة
        // ============================================
        return response()->json([
            'success' => true,
            'message' => 'تم تغيير حالة الشكوى بنجاح، وتم إرسال إشعارات للطرفين',
            'data'    => [
                'complaint'          => $complaint,
                'old_status'         => $oldStatus,
                'old_status_text'    => $oldStatusText,
                'new_status'         => $complaint->status,
                'new_status_text'    => $complaint->status_text,
                'notifications_sent' => true,
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

    } catch (QueryException $e) {
        Log::error('DB error in update complaint status', [
            'error' => $e->getMessage(),
            'id'    => $id,
        ]);

        return response()->json([
            'success'     => false,
            'message'     => 'حدث خطأ في قاعدة البيانات',
            'error_type'  => 'database_error',
            'status_code' => 500,
        ], 500);

    } catch (\Exception $e) {
        Log::error('Error in update complaint status', [
            'error' => $e->getMessage(),
            'id'    => $id,
        ]);

        return response()->json([
            'success'     => false,
            'message'     => 'حدث خطأ أثناء تغيير حالة الشكوى',
            'error'       => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
            'error_type'  => 'general_error',
            'status_code' => 500,
        ], 500);
    }
}
}