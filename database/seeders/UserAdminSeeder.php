<?php

namespace Database\Seeders;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        
    
        // قائمة الـ Admins
        $admins = [
            [
                'name' => 'Ahmad Farwan',
                'phone' => '0912345683',
                'password' => Hash::make('Admin1@2024#Secure'),
                'language' => 'en',
            ],
            // [
            //     'name' => 'Tasneem Farawan',
            //     'phone' => '0912345684',
            //     'password' => Hash::make('Admin2@2024#Secure'),
            //     'language' => 'en',
            // ],
        ];

        // إنشاء الـ Admins
        foreach ($admins as $adminData) {
            $admin = User::create($adminData);
            
            Role::create([
                'role' => 1, // Admin
                'user_id' => $admin->id,
            ]);
        }
    }
}
