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
        Schema::create('parking_spots', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->string('title');
            $table->string('address');
            $table->float('latitude');
            $table->float('longitude');
            $table->decimal('price_per_hour')->nullable();
            $table->decimal('price_per_day')->nullable();
            $table->decimal('price_per_month')->nullable();
            $table->boolean('is_available')->default(1);//متاح1 
            $table->time('time_from');
            $table->time('time_to');
            $table->string('cancellation_policy');
            $table->boolean('is_active')->default(0);//مفعل من الادمن 1
            $table->integer('capacity');
            $table->json('work_days');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parking_spots');
    }
};
