<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{

 use HasFactory;

 protected $table='password_resets';
    protected $fillable = [
        'phone',
        'otp',
        'expires_at',
        'used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used' => 'boolean',
    ];

    public function isValid()
    {
        return !$this->used && $this->expires_at->isFuture();
    }
}