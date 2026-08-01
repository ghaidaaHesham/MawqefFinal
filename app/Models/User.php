<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\MorphMany;

use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
     protected $table='users';
    protected $fillable = [
        'name',
        'phone',
        'password',
        'language',
        'fcm_token',
        'is_blocked',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function role()
    {
        return $this->hasOne(Role::class);
    }
     public function withdrawal()
    {
     return $this->hasMany(Withdrawal_request::class);

    }
     public function booking()
    {
     return $this->hasMany(Booking::class);

    }

     public function parkingSpot()
    {
     return $this->hasMany(Parking_spot::class);

    }

     public function notification()
    {
     return $this->hasMany(Notification_log::class);

    }
     public function audit()
    {
     return $this->hasMany(Audit_log::class);

    }
     public function dispute()
    {
     return $this->hasMany(Dispute::class);

    }
    public function transiction()
    {
        return $this->hasOne(Transiction::class);
    }
    // علاقة الصور (مورف)
    public function images(): MorphMany
    {
        return $this->morphMany(imageUser::class, 'imageable');
    }
    
    public function vehicle()
    {
        return $this->hasOne(vehicle::class, 'id_user');
    }

    public function address()
    {
        return $this->hasOne(Address::class, 'user_id');
    }
    
     // علاقة الصور (مورف)
    public function imagesdocu(): MorphMany
    {
        return $this->morphMany(Image_documents::class, 'imageable');
    }

    public function image_sham()
    {
        return $this->hasOne(Sham_cash::class, 'user_id');
    }
    
}
