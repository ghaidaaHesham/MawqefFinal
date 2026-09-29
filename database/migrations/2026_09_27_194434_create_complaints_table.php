<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();

            // ===== الأطراف =====
            $table->foreignId('complainant_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('complained_against_id')->constrained('users')->onDelete('cascade');

            // ===== المراجع (اختياري) =====
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->onDelete('set null');
            $table->foreignId('parking_id')->nullable()->constrained('parking_spots')->onDelete('set null');

            // ===== محتوى الشكوى =====
            $table->string('subject', 191);
            $table->text('message');

            // ===== الحالة =====
            $table->enum('status', ['pending', 'in_review', 'resolved', 'rejected'])
                  ->default('pending');

            // ===== رد الإدمن =====
            $table->text('admin_response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->index(['complainant_id', 'status']);
            $table->index(['complained_against_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};