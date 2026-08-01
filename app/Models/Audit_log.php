<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Audit_log extends Model
{
    //يسجل جميع الإجراءات الحساسة التي قام بها المستخدمون (خاصة الإداريين) لأغراض أمنية وقانونية
    use HasFactory;
     protected $table='audit_logs';
    protected $fillable = [
        'user_id',// المستخدم الذي قام بالعملية
        'action',//اسم العملية
        'details',//تفاصيل إضافية  مثلا احتيال 
        'ip_address',
        
    ];

     public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }
}
