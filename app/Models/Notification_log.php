<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification_log extends Model
{
    use HasFactory;
     protected $table='notification_logs';
    protected $fillable = [
        'user_id',//المستخدم الذي أرسل له الإشعار
        'title',//مثال: "تم تأكيد حجزك"
        'body',//مثال: "تم حجز موقفك بنجاح في الساعة 10:00"
        'is_read',//هل قام المستخدم بفتح الإشعار وقراءته؟
        
    ];


    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }
}
