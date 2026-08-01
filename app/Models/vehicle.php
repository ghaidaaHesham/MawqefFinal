<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class vehicle extends Model
{
    
     use HasFactory;
     protected $table='vehicle';
    protected $fillable = [
        
        'id_user',
        'plate_number',
        'vehicle_type',
        'vehicle_color',
        
    ];
     public function user()
    {
        return $this->belongsTo(User::class,'id_user');
    }

}
