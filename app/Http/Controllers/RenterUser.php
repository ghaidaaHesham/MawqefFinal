<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RenterUser extends Controller
{
    /**
     * عرض جميع المستخدمين العاديين (role = 3)
     */
    public function index(Request $request)
    {
        try {
            // 1. التحقق من أن المستخدم الحالي لديه صلاحية (Admin فقط)
            $currentUser = $request->user();
            
            // التحقق من دور المستخدم الحالي (يجب أن يكون Admin role = 1)
            $currentUserRole = Role::where('user_id', $currentUser->id)->first();
            
            if (!$currentUserRole || $currentUserRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك، فقط المدير يمكنه عرض المستخدمين',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 2. بناء الاستعلام لجلب المستخدمين العاديين (role = 3)
            $query = User::whereHas('role', function ($q) {
                $q->where('role', 3);
            });

            // 3. البحث بالاسم أو رقم الهاتف
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            }

            // 4. البحث برقم الهاتف
            if ($request->has('phone') && $request->phone) {
                $query->where('phone', 'LIKE', "%{$request->phone}%");
            }

            // 5. البحث بالاسم
            if ($request->has('name') && $request->name) {
                $query->where('name', 'LIKE', "%{$request->name}%");
            }

            // 6. البحث باللغة
            if ($request->has('language') && $request->language) {
                $query->where('language', $request->language);
            }

            // 7. البحث بالتاريخ (من - إلى)
            if ($request->has('from_date') && $request->from_date) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date') && $request->to_date) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // 8. ترتيب النتائج
            $sortField = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            
            $allowedSortFields = ['id', 'name', 'phone', 'language', 'created_at', 'updated_at'];
            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortOrder);
            }

            // 9. عدد النتائج في الصفحة
            $perPage = $request->get('per_page', 15);
            if ($perPage > 100) {
                $perPage = 100;
            }

            // 10. جلب النتائج
            $users = $query->paginate($perPage);

            // 11. إضافة بيانات الدور لكل مستخدم
            $users->getCollection()->transform(function ($user) {
                $role = Role::where('user_id', $user->id)->first();
                $user->role_data = $role;
                return $user;
            });

            // 12. تسجيل عملية العرض
            Log::info('تم عرض المستخدمين العاديين', [
                'user_id' => $currentUser->id,
                'name' => $currentUser->name,
                'ip' => $request->ip(),
                'filters' => $request->all()
            ]);

            // 13. إرجاع الاستجابة
            return response()->json([
                'success' => true,
                'message' => 'تم جلب المستخدمين بنجاح',
                'data' => $users,
                'meta' => [
                    'total' => $users->total(),
                    'per_page' => $users->perPage(),
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'filters' => [
                        'search' => $request->search,
                        'phone' => $request->phone,
                        'name' => $request->name,
                        'language' => $request->language,
                        'from_date' => $request->from_date,
                        'to_date' => $request->to_date,
                        'sort_by' => $sortField,
                        'sort_order' => $sortOrder,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('خطأ في عرض المستخدمين', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء عرض المستخدمين',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي',
                'error_type' => 'general_error',
                'status_code' => 500
            ], 500);
        }
    }

    /**
     * عرض مستخدم محدد
     */
    public function show(Request $request, $id)
    {
        try {
            // 1. التحقق من صلاحيات المستخدم
            $currentUser = $request->user();
            $currentUserRole = Role::where('user_id', $currentUser->id)->first();
            
            if (!$currentUserRole || $currentUserRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 2. جلب المستخدم
            $user = User::whereHas('role', function ($q) {
                $q->where('role', 3);
            })->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'المستخدم غير موجود',
                    'error_type' => 'not_found',
                    'status_code' => 404
                ], 404);
            }

            // 3. جلب دور المستخدم
            $role = Role::where('user_id', $user->id)->first();

            // 4. إرجاع البيانات
            return response()->json([
                'success' => true,
                'message' => 'تم جلب بيانات المستخدم بنجاح',
                'data' => [
                    'user' => $user,
                    'role' => $role
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء عرض المستخدم',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * عرض إحصائيات المستخدمين
     */
    public function stats(Request $request)
    {
        try {
            // 1. التحقق من صلاحيات المستخدم
            $currentUser = $request->user();
            $currentUserRole = Role::where('user_id', $currentUser->id)->first();
            
            if (!$currentUserRole || $currentUserRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك',
                    'error_type' => 'unauthorized',
                    'status_code' => 403
                ], 403);
            }

            // 2. جلب الإحصائيات
            $totalUsers = User::whereHas('role', function ($q) {
                $q->where('role', 3);
            })->count();

            // إحصائيات حسب اللغة
            $languageStats = User::whereHas('role', function ($q) {
                $q->where('role', 3);
            })->selectRaw('language, count(*) as count')
              ->groupBy('language')
              ->get();

            // إحصائيات حسب الشهر
            $monthlyStats = User::whereHas('role', function ($q) {
                $q->where('role', 3);
            })->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, count(*) as count')
              ->groupBy('month')
              ->orderBy('month', 'desc')
              ->limit(12)
              ->get();

            // أحدث المستخدمين
            $recentUsers = User::whereHas('role', function ($q) {
                $q->where('role', 3);
            })->orderBy('created_at', 'desc')
              ->limit(5)
              ->get();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الإحصائيات بنجاح',
                'data' => [
                    'total' => $totalUsers,
                    'by_language' => $languageStats,
                    'monthly_stats' => $monthlyStats,
                    'recent_users' => $recentUsers
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب الإحصائيات',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * تصدير بيانات المستخدمين (اختياري)
     */
    public function export(Request $request)
    {
        try {
            // التحقق من صلاحيات المستخدم
            $currentUser = $request->user();
            $currentUserRole = Role::where('user_id', $currentUser->id)->first();
            
            if (!$currentUserRole || $currentUserRole->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'غير مصرح لك'
                ], 403);
            }

            // جلب جميع المستخدمين
            $users = User::whereHas('role', function ($q) {
                $q->where('role', 3);
            })->get();

            // تحويل البيانات إلى CSV
            $csvData = [];
            $csvData[] = ['ID', 'الاسم', 'رقم الهاتف', 'اللغة', 'تاريخ الإنشاء'];
            
            foreach ($users as $user) {
                $csvData[] = [
                    $user->id,
                    $user->name,
                    $user->phone,
                    $user->language,
                    $user->created_at
                ];
            }

            // إرجاع ملف CSV
            return response()->json([
                'success' => true,
                'data' => $csvData
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء التصدير'
            ], 500);
        }
    }
}