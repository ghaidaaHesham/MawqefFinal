<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'password',
        'language',
        'fcm_token',
        'is_blocked',
        'failed_login_attempts',
        'locked_until',
        'last_failed_attempt_at',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'locked_until' => 'datetime',
        'last_failed_attempt_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_blocked' => 'boolean',
        'failed_login_attempts' => 'integer',
    ];

    public function role()
    {
        return $this->hasOne(Role::class);
    }

    public function images()
    {
        return $this->morphMany(imageUser::class, 'imageable');
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function getRemainingLockMinutes(): int
    {
        if (!$this->isLocked()) {
            return 0;
        }
        return max(0, now()->diffInMinutes($this->locked_until));
    }

    public function isBlocked(): bool
    {
        return $this->is_blocked == 1;
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

    public function sham()
    {
        return $this->hasOne(Sham_cash::class, 'user_id');
    }
     
  




    
}
