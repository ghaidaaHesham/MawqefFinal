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
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->uuid("booking_id");
            $table->uuid('user_id');//من أبلغ
            
            $table->string('reason');
            $table->string('resolution')->nullable();//قرار الادارة
            $table->enum('status',['open','under_review','resolved','rejected'])->default("open");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
