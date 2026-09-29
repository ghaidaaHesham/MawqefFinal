<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // ===== المعرف الأساسي =====
            $table->id();
            
            // ===== المعلومات الأساسية =====
            $table->string('name');
            $table->string('phone')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('language', ['ar', 'en'])->default('en');
            
            // ===== الإشعارات =====
            // رمز خاص بجهاز المستخدم، يستخدم لإرسال الإشعارات الفورية
            $table->string('fcm_token')->nullable();
            
            // ===== حالة الحساب =====
            // ✅ القيمة الافتراضية 0 (غير محظور) بدلاً من 1
            $table->boolean('is_blocked')->default(0);
            
            // ===== ✅ حقول الأمان (Login Security) =====
            // عدد محاولات تسجيل الدخول الفاشلة
            $table->integer('failed_login_attempts')->default(0);
            
            // تاريخ انتهاء قفل الحساب (بعد 5 محاولات فاشلة)
            $table->timestamp('locked_until')->nullable();
            
            // تاريخ آخر محاولة فاشلة
            $table->timestamp('last_failed_attempt_at')->nullable();
            
            // تاريخ آخر تسجيل دخول ناجح
            $table->timestamp('last_login_at')->nullable();
            
            // IP آخر تسجيل دخول ناجح
            $table->string('last_login_ip', 45)->nullable();
            
            // ===== التوكنات =====
            $table->rememberToken();
            
            // ===== التواريخ =====
            $table->timestamps();
            $table->softDeletes();
            
            // ===== الفهارس (Indexes) =====
            $table->index('is_blocked');
            $table->index('locked_until');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};