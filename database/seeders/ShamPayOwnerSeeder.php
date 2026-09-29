<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\PaymentAmount;

class ShamPayOwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // حذف جميع البيانات الموجودة
        PaymentAmount::truncate();

        // إضافة بيانات جديدة
        PaymentAmount::create([
            'payment_amount' => 5000,   // المبلغ للكل 
            'booking_number' => 10,      // عدد الحجوزات في الموقف
        ]);

        
    }
}