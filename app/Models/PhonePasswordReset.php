<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhonePasswordReset extends Model
{
    use HasFactory;

    protected $table = 'phone_password_resets';

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

    public static function createFor(int $userId): self
    {
        self::where('user_id', $userId)->delete();

        return self::create([
            'user_id'    => $userId,
            'token'      => bin2hex(random_bytes(32)),
            'expires_at' => now()->addMinutes(self::TOKEN_EXPIRY_MINUTES),
            'is_used'    => false,
        ]);
    }

    public static function findValid(string $token): ?self
    {
        return self::where('token', $token)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function markAsUsed(): void
    {
        $this->update(['is_used' => true]);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}