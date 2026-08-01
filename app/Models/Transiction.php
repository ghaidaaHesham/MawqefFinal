<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transiction extends Model
{

    //يتتبع حركة الأموال بدقة: المبالغ التي دفعها المستأجر، العمولة التي أخذتها المنصة، والأموال المحولة لصاحب الموقف.
    use HasFactory;
     protected $table='transictions';
    protected $fillable = [
        'booking_id',
        'sender_id',
        'receiver_id',
        'amount',//المبلغ المالي الأساسي
        'commission',//عمولة المنصة
        'type',
        /*نوع المعاملة:
        - payment: دفع من المستأجر.
        - payout: تحويل لصاحب الموقف.
        - refund: استرداد أموال للمستأجر*/
        'status',
        /* pending (قيد التنفيذ)، completed (تمت بنجاح)، failed (فشلت).حالة المعاملة*/
        'gateway',//بوابة الدفع المستخدمة
        'gateway_transaction_id',//رقم المعاملة من بوابة الدفع (للتتبع والمراجعة) 
    ];

    public function booking()
        {
            return $this->belongsTo(Booking::class,'booking_id');
        }

        public function user()
    {
        return $this->belongsTo(User::class);
    }

}
