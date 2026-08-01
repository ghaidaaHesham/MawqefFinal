<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;


class Sham_cash extends Model
{
    use HasFactory;
     protected $table='sham_cashes';
    protected $fillable = [
        
        'name_in_sham',
        'user_id',
        
    ];
     public function images(): MorphMany
    {
        return $this->morphMany(imageShamCash::class, 'imageable');
    }

     public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
