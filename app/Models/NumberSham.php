<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NumberSham extends Model
{
    use HasFactory;
     protected $table='numbersham';
    protected $fillable = [
        
        'number_sham',
        'id_parking',
       
        
    ];


      public function parking()
    {
        return $this->belongsTo(Parking_spot::class,'id_parking');
    }
    
}
