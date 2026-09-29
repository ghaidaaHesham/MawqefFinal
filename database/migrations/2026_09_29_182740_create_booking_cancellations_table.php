<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_cancellations', function (Blueprint $table) {
            $table->id();

            // ===== الحجز المرتبط =====
            $table->foreignId('booking_id')
                  ->constrained('bookings')
                  ->onDelete('cascade');

            // ===== الأطراف =====
            $table->foreignId('requested_by')
                  ->constrained('users')
                  ->onDelete('cascade'); // المستأجر

            $table->foreignId('responded_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null'); // صاحب الموقف

            // ===== تفاصيل الطلب =====
            $table->text('cancellation_reason');
            $table->timestamp('cancellation_requested_at');

            // ===== رد صاحب الموقف =====
            $table->enum('decision', ['pending', 'approved', 'rejected'])
                  ->default('pending');
            $table->text('cancellation_response')->nullable();
            $table->timestamp('cancellation_responded_at')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_cancellations');
    }
};