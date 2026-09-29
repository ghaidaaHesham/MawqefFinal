<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;

class ShamPayOwner extends Model
{
    
   use HasFactory;
     protected $table='shampayowner';
    protected $fillable = [
        
        'name_in_sham',
        'user_id',
        'parking_id',
        
    ];
     public function images(): MorphMany
    {
        return $this->morphMany(imageshampayowner::class, 'imageable');
    }

     public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parking()
    {
        return $this->belongsTo(Parking_spot::class, 'parking_id');
    }


}


