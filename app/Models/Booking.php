<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;
     protected $table='bookings';
    protected $fillable = [
        'spot_id',
        'user_id',
        'start_time',
        'end_time',
        'totale_amount',
        'status',
        /**:
- pending: في انتظار الدفع.
- paid: تم الدفع، ينتظر وقت البدء.
- active: جاري الاستخدام (بدأ الوقت).
- completed: انتهى الحجز بنجاح.
- cancelled: ألغي من قبل المستأجر.
- expired: تم إلغاؤه تلقائياً لعدم الدفع. */
        'payment_intent_id',
        /*رقم تعريف عملية الدفع من بوابة الدفع*/
    ];



    public function dispute()
    {
        return $this->hasOne(Dispute::class);
    }
    public function transiction()
    {
        return $this->hasOne(Transiction::class);
    }

     public function parkingSpot()
    {
        return $this->belongsTo(Parking_spot::class, 'spot_id');
    }

    // علاقة المستخدم (المستأجر)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
