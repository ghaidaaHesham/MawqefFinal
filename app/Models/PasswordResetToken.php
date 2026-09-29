<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'is_used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used'    => 'boolean',
    ];

    public const TOKEN_EXPIRY_MINUTES = 10;

    /**
     * إنشاء token جديد للمستخدم
     */
    public static function createFor(string $userId): self
    {
        // حذف أي tokens سابقة لهذا المستخدم
        self::where('user_id', $userId)->delete();

        return self::create([
            'user_id'    => $userId,
            'token'      => bin2hex(random_bytes(32)), // 64 حرف
            'expires_at' => now()->addMinutes(self::TOKEN_EXPIRY_MINUTES),
            'is_used'    => false,
        ]);
    }

    /**
     * البحث عن token صالح
     */
    public static function findValid(string $token): ?self
    {
        return self::where('token', $token)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * التحقق من الصلاحية
     */
    public function isValid(): bool
    {
        return !$this->is_used && $this->expires_at->isFuture();
    }

    /**
     * تعليم كـ مستخدم
     */
    public function markAsUsed(): void
    {
        $this->update(['is_used' => true]);
    }

    /**
     * العلاقة مع المستخدم
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}