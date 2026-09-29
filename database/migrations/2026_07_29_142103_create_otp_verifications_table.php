<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();

            // ===== المعلومات الأساسية =====
            $table->string('phone', 20)->index();
            $table->string('otp', 6);

            // ===== الغرض =====
            $table->enum('purpose', [
                'password_reset',
                'phone_verification',
                'login',
                'register',
            ])->default('password_reset');

            // ===== الحالة =====
            $table->boolean('is_used')->default(false);
            $table->timestamp('expires_at');

            // ===== المحاولات =====
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('last_attempt_at')->nullable();

            // ===== معلومات الطلب =====
            $table->string('requested_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // ===== التواريخ =====
            $table->timestamps();

            // ===== الفهارس =====
            $table->index(['phone', 'purpose', 'is_used'], 'idx_phone_purpose_active');
            $table->index(['expires_at', 'is_used'], 'idx_expires_active');
            $table->index('requested_ip');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');
    }
};