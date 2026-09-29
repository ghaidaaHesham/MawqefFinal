<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class OTPVerification extends Model
{
    use HasFactory;

    // ============================================
    // Purposes Constants
    // ============================================
    public const PURPOSE_PASSWORD_RESET     = 'password_reset';
    public const PURPOSE_PHONE_VERIFICATION = 'phone_verification';
    public const PURPOSE_LOGIN              = 'login';
    public const PURPOSE_REGISTER           = 'register';

    // ============================================
    // Table Configuration
    // ============================================
    protected $table = 'otp_verifications';

    protected $fillable = [
        'phone',
        'otp',
        'purpose',
        'expires_at',
        'is_used',
        'attempts',
        'max_attempts',
        'last_attempt_at',
        'requested_ip',
        'user_agent',
    ];

    protected $casts = [
        'expires_at'      => 'datetime',
        'last_attempt_at' => 'datetime',
        'is_used'         => 'boolean',
        'attempts'        => 'integer',
        'max_attempts'    => 'integer',
    ];

    protected $hidden = [
        'otp',
    ];

    // ============================================
    // Scopes
    // ============================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_used', false);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }

    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    public function scopeForIp(Builder $query, string $ip): Builder
    {
        return $query->where('requested_ip', $ip);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->active()->notExpired();
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', today());
    }

    // ============================================
    // State Methods
    // ============================================

    public function isValid(): bool
    {
        return !$this->is_used && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canAttempt(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    // ============================================
    // Accessors
    // ============================================

    public function getRemainingAttemptsAttribute(): int
    {
        return max(0, $this->max_attempts - $this->attempts);
    }

    public function getRemainingSecondsAttribute(): int
    {
        if ($this->isExpired()) {
            return 0;
        }
        return max(0, now()->diffInSeconds($this->expires_at));
    }

    // ============================================
    // Business Logic
    // ============================================

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
        $this->update(['last_attempt_at' => now()]);
    }

    public function markAsUsed(): void
    {
        $this->update(['is_used' => true]);
    }

    /**
     * التحقق من الرمز مع زيادة المحاولات
     */
    public function verifyCode(string $otp): bool
    {
        // 1. التحقق من الصلاحية
        if (!$this->isValid()) {
            return false;
        }

        // 2. التحقق من عدد المحاولات
        if (!$this->canAttempt()) {
            return false;
        }

        // 3. زيادة المحاولات أولاً (قبل التحقق)
        $this->incrementAttempts();

        // 4. مقارنة آمنة لمنع Timing Attack
        if (!hash_equals($this->otp, $otp)) {
            return false;
        }

        // 5. تعليم كمستخدم
        $this->markAsUsed();

        return true;
    }

    // ============================================
    // Static Factory Methods
    // ============================================

    public static function generateCode(): string
    {
        $length = config('otp.length', 6);
        $max = (int) str_repeat('9', $length);

        return str_pad(
            (string) random_int(0, $max),
            $length,
            '0',
            STR_PAD_LEFT
        );
    }

    public static function createFor(
        string $phone,
        string $purpose = self::PURPOSE_PASSWORD_RESET
    ): self {
        // حذف جميع OTPs السابقة لنفس الهاتف والغرض
        self::where('phone', $phone)
            ->where('purpose', $purpose)
            ->delete();

        return self::create([
            'phone'        => $phone,
            'otp'          => self::generateCode(),
            'purpose'      => $purpose,
            'expires_at'   => now()->addMinutes(config('otp.expiry_minutes', 5)),
            'is_used'      => false,
            'attempts'     => 0,
            'max_attempts' => config('otp.max_attempts', 3),
            'requested_ip' => request()?->ip(),
            'user_agent'   => substr(request()?->userAgent() ?? '', 0, 500),
        ]);
    }

    // ============================================
    // Static Query Methods
    // ============================================

    public static function findActive(
        string $phone,
        string $purpose = self::PURPOSE_PASSWORD_RESET
    ): ?self {
        return self::forPhone($phone)
            ->forPurpose($purpose)
            ->valid()
            ->latest()
            ->first();
    }

    public static function findLastSent(
        string $phone,
        string $purpose = self::PURPOSE_PASSWORD_RESET
    ): ?self {
        return self::forPhone($phone)
            ->forPurpose($purpose)
            ->latest()
            ->first();
    }

    public static function countTodayForPhone(string $phone): int
    {
        return self::forPhone($phone)->today()->count();
    }

    public static function countTodayForIp(string $ip): int
    {
        return self::forIp($ip)->today()->count();
    }

    /**
     * التحقق من إمكانية إرسال OTP جديد
     */
    public static function checkCanSend(
        string $phone,
        string $purpose = self::PURPOSE_PASSWORD_RESET
    ): array {
        $cooldownSeconds = config('otp.cooldown_seconds', 60);
        $maxPerDay       = config('otp.max_per_day_phone', 5);
        $maxPerDayIp     = config('otp.max_per_day_ip', 20);

        // 1. Cooldown Check
        $lastOtp = self::findLastSent($phone, $purpose);

        if ($lastOtp) {
            $secondsSince = $lastOtp->created_at->diffInSeconds(now());

            if ($secondsSince < $cooldownSeconds) {
                return [
                    'allowed'      => false,
                    'reason'       => 'cooldown',
                    'message'      => 'يرجى الانتظار قبل طلب رمز جديد',
                    'wait_seconds' => $cooldownSeconds - $secondsSince,
                ];
            }
        }

        // 2. Daily Phone Limit
        $phoneCount = self::countTodayForPhone($phone);

        if ($phoneCount >= $maxPerDay) {
            return [
                'allowed'     => false,
                'reason'      => 'phone_daily_limit',
                'message'     => 'تم تجاوز الحد اليومي لهذا الرقم، يرجى المحاولة غداً',
                'daily_count' => $phoneCount,
                'max_per_day' => $maxPerDay,
            ];
        }

        // 3. Daily IP Limit
        $ip = request()?->ip();

        if ($ip) {
            $ipCount = self::countTodayForIp($ip);

            if ($ipCount >= $maxPerDayIp) {
                return [
                    'allowed'     => false,
                    'reason'      => 'ip_daily_limit',
                    'message'     => 'تم تجاوز الحد المسموح من هذا الجهاز',
                    'ip_count'    => $ipCount,
                    'max_per_day' => $maxPerDayIp,
                ];
            }
        }

        return [
            'allowed'     => true,
            'phone_count' => $phoneCount,
            'max_per_day' => $maxPerDay,
        ];
    }

    /**
     * تنظيف OTPs المنتهية
     */
    public static function cleanup(): int
    {
        $hours = config('otp.cleanup_after_hours', 24);

        return self::where('created_at', '<', now()->subHours($hours))
            ->delete();
    }
}