<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;

class Dispute extends Model
{
    //يسجل الشكاوى بين المستأجر وصاحب الموقف
    use HasFactory;
     protected $table='disputes';
    protected $fillable = [
        'booking_id',
        'user_id',//أي الشخص الذي أبلغ عن المشكلة (مستأجر أو مالك)
        'reason',//سبب النزاع (يكتبه المستخدم)
        'resolution',//قرار الإدارة
        'status',//حالة النزاع
        
    ];

    // public function images(): MorphMany
    // {
    //     return $this->morphMany(Image::class, 'imageable');
    // }

     public function booking()
    {
        return $this->belongsTo(Booking::class,'booking_id');
    }

     public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }
}
