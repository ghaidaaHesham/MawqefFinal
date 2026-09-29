<?php

namespace App\Services;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthService
{
    public function __construct(
        private LoginAttemptService $loginAttemptService,
        private ImageService $imageService
    ) {}

    public function registerUser(
        array $data,
        int $roleId,
        ?UploadedFile $image = null
    ): array {
        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
                'language' => $data['language'] ?? 'en',
                'is_blocked' => 0,
            ]);

            $uploadedImage = null;
            if ($image) {
                $uploadedImage = $this->imageService->uploadUserImage(
                    $image,
                    $user->id,
                    $user->name
                );
            }

            $role = Role::create([
                'role' => $roleId,
                'user_id' => $user->id,
            ]);

            $token = $user->createToken('ghaidaaProjectToken')->plainTextToken;

            DB::commit();

            Log::info('تم تسجيل مستخدم جديد', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'role' => $roleId,
                'has_image' => $uploadedImage ? true : false,
                'ip' => request()->ip(),
            ]);

            return [
                'user' => $user->load('role'),
                'role' => $role,
                'image' => $uploadedImage,
                'token' => $token,
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('فشل تسجيل المستخدم - تم التراجع', [
                'error' => $e->getMessage(),
                'phone' => $data['phone'] ?? null,
            ]);

            throw $e;
        }
    }

    public function login(string $phone, string $password): array
    {
        $user = User::where('phone', $phone)->first();

        if ($this->loginAttemptService->isAccountLocked($user)) {
            $remaining = $this->loginAttemptService->getRemainingLockMinutes($user);

            Log::warning('محاولة دخول لحساب مقفل', [
                'user_id' => $user->id,
                'phone' => $phone,
                'remaining_minutes' => $remaining,
                'ip' => request()->ip(),
            ]);

            throw new \Exception("الحساب مقفل مؤقتاً، يرجى المحاولة بعد {$remaining} دقيقة");
        }

        if (!$user || !Hash::check($password, $user->password)) {
            $this->loginAttemptService->recordFailedAttempt($user);

            Log::warning('محاولة دخول فاشلة', [
                'phone' => $phone,
                'ip' => request()->ip(),
            ]);

            throw new \Exception('بيانات الدخول غير صحيحة');
        }

        if ($user->is_blocked == 1) {
            Log::warning('محاولة دخول لحساب محظور', [
                'user_id' => $user->id,
                'phone' => $phone,
                'ip' => request()->ip(),
            ]);

            throw new \Exception('الحساب محظور، يرجى التواصل مع الدعم');
        }

        $role = Role::where('user_id', $user->id)->first();
        if (!$role) {
            throw new \Exception('لا يوجد دور للمستخدم');
        }

        $this->loginAttemptService->resetAttempts($user);

        $user->tokens()->delete();

        $token = $user->createToken('ghaidaaProjectToken')->plainTextToken;

        Log::info('تسجيل دخول ناجح', [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'role' => $role->role,
            'ip' => request()->ip(),
        ]);

        return [
            'user' => $user,
            'role' => $role,
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->tokens()->delete();

        Log::info('تسجيل خروج', [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'ip' => request()->ip(),
        ]);
    }
}