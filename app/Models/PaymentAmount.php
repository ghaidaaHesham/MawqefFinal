<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAmount extends Model
{
    use HasFactory;
    
    protected $table = 'paymentamount';
    
    protected $fillable = [
        'payment_amount',
        'booking_number',
       
    ];
}
