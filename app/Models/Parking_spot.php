<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;

class Parking_spot extends Model
{
    use HasFactory;
    
    protected $table = 'parking_spots';
    
    protected $fillable = [
        'user_id',
        'title',
        'address',
        'latitude',
        'longitude',
        'capacity',
        'price_per_hour',
        'price_per_day',
        'price_per_month',
        'is_available',
        'time_from',
        'time_to',
        'cancellation_policy',
        'is_active',
        'work_days',
    ];

    // علاقة الصور (مورف)
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    // علاقة المستخدم
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // علاقة الحجوزات (تم تعديل اسم العمود إلى spot_id)
    public function bookings()
    {
        return $this->hasMany(Booking::class, 'spot_id');
    }

    
    public function documents(): MorphMany
    {
        return $this->morphMany(Image_documents::class, 'imageable');
    }
 


    // في Model Parking_spot

/**
 * الحصول على أيام العمل كمصفوفة
 */
public function getWorkDaysAttribute($value)
{
    return json_decode($value, true);
}

/**
 * تعيين أيام العمل كـ JSON
 */
public function setWorkDaysAttribute($value)
{
    $this->attributes['work_days'] = json_encode($value);
}
}