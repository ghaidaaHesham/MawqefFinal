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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('spot_id');
            $table->uuid('user_id');//renter_id
            $table->datetime('start_time');
            $table->datetime('end_time');
            $table->decimal('totale_amount');
            $table->enum('status',['pending','paid','active','completed','cancelled','expired']);
            $table->string('payment_intent_id')->nullable();

            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
