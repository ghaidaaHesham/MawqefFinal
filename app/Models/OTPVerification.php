<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class OTPVerification extends Model
{
    use HasFactory;

    protected $table = 'otp_verifications';

    protected $fillable = [
        'phone',
        'otp',
        'expires_at',
        'is_used',
        'attempts',
        'last_attempt_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean',
        'last_attempt_at' => 'datetime',
    ];

    public function isValid(): bool
    {
        return !$this->is_used && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canAttempt(): bool
    {
        return $this->attempts < 3;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
        $this->last_attempt_at = now();
        $this->save();
    }

    public static function generateOTP(): string
    {
        return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function createOTP(string $phone): self
    {
        self::where('phone', $phone)->where('is_used', false)->delete();

        $otp = self::generateOTP();

        return self::create([
            'phone' => $phone,
            'otp' => $otp,
            'expires_at' => Carbon::now()->addMinutes(5),
            'is_used' => false,
            'attempts' => 0,
        ]);
    }
}