<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Complaint extends Model
{
    use HasFactory;

    protected $table = 'complaints';

    protected $fillable = [
        'complainant_id',
        'complained_against_id',
        'booking_id',
        'parking_id',
        'subject',
        'message',
        'status',
        'admin_response',
        'responded_by',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    // ===== الثوابت =====
    public const STATUS_PENDING   = 'pending';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUS_REJECTED  = 'rejected';

    // ===== العلاقات =====

    /** مقدّم الشكوى */
    public function complainant()
    {
        return $this->belongsTo(User::class, 'complainant_id');
    }

    /** المشتكى عليه */
    public function complainedAgainst()
    {
        return $this->belongsTo(User::class, 'complained_against_id');
    }

    /** الحجز المرتبط */
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /** الموقف المرتبط */
    public function parking()
    {
        return $this->belongsTo(Parking_spot::class, 'parking_id');
    }

    /** الإدمن الذي رد */
    public function responder()
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /** الصور */
    public function images(): MorphMany
    {
        return $this->morphMany(ComplaintImage::class, 'imageable');
    }

    // ===== Accessors =====

    /** حالة الشكوى كنص عربي */
    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING   => 'قيد الانتظار',
            self::STATUS_IN_REVIEW => 'قيد المراجعة',
            self::STATUS_RESOLVED  => 'تم الحل',
            self::STATUS_REJECTED  => 'مرفوضة',
            default                => $this->status,
        };
    }
}