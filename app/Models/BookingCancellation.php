<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingCancellation extends Model
{
    use HasFactory;

    protected $table = 'booking_cancellations';

    protected $fillable = [
        'booking_id',
        'requested_by',
        'responded_by',
        'cancellation_reason',
        'cancellation_requested_at',
        'decision',
        'cancellation_response',
        'cancellation_responded_at',
    ];

    protected $casts = [
        'cancellation_requested_at' => 'datetime',
        'cancellation_responded_at' => 'datetime',
    ];

    // ===== الثوابت =====
    public const DECISION_PENDING  = 'pending';
    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    // ===== العلاقات =====

    /** الحجز المرتبط */
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /** المستأجر الذي طلب الإلغاء */
    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** صاحب الموقف الذي رد */
    public function responder()
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    // ===== Accessors =====

    /** حالة القرار كنص عربي */
    public function getDecisionTextAttribute(): string
    {
        return match ($this->decision) {
            self::DECISION_PENDING  => 'قيد الانتظار',
            self::DECISION_APPROVED => 'تمت الموافقة',
            self::DECISION_REJECTED => 'تم الرفض',
            default                 => $this->decision,
        };
    }
}