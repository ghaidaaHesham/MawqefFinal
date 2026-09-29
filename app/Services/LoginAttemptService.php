<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class LoginAttemptService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_DURATION_MINUTES = 15;

    public function isAccountLocked(?User $user): bool
    {
        if (!$user || !$user->locked_until) {
            return false;
        }

        return $user->locked_until->isFuture();
    }

    public function getRemainingLockMinutes(?User $user): int
    {
        if (!$user || !$user->locked_until) {
            return 0;
        }

        return max(0, now()->diffInMinutes($user->locked_until));
    }

    public function recordFailedAttempt(?User $user): void
    {
        if (!$user) {
            return;
        }

        $user->failed_login_attempts += 1;
        $user->last_failed_attempt_at = now();

        if ($user->failed_login_attempts >= self::MAX_ATTEMPTS) {
            $user->locked_until = now()->addMinutes(self::LOCK_DURATION_MINUTES);

            Log::warning('تم قفل الحساب بعد محاولات فاشلة', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'failed_attempts' => $user->failed_login_attempts,
                'locked_until' => $user->locked_until,
            ]);
        }

        $user->save();
    }

    public function resetAttempts(User $user): void
    {
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->last_failed_attempt_at = null;
        $user->last_login_at = now();
        $user->last_login_ip = request()->ip();
        $user->save();
    }
}