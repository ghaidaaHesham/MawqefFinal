<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Withdrawal_request extends Model
{
    use HasFactory;
     protected $table='withdrawal_requests';
    protected $fillable = [
        'user_id',//(صاحب الموقف الذي يطلب السحب)
        'amount',//المبلغ المطلوب سحبه
        'status',/*حالة الطلب: pending (قيد المراجعة)، processing (قيد التنفيذ)، completed (تم التحويل)، failed (فشل).*/
        'method',//طريقة السحب
        'account_details',//مثال : رقم المحفظة
        
    ];


    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }
}
