<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class System_config extends Model
{
    use HasFactory;
     protected $table='system_configs';
    protected $fillable = [
        'value',//قيمة الإعداد (مثال: 10 يعني 10%)
        'descripion',//	شرح مختصر لهذا الإعداد (مثال: "نسبة العمولة التي تأخذها المنصة من كل حجز").
      
    ];

} 
