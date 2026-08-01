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
        Schema::create('transictions', function (Blueprint $table) {
            $table->id();
            $table->uuid('booking_id');
            $table->uuid('sender_id');
            $table->uuid('receiver_id');
            $table->decimal('amount');
            $table->decimal('commission');
            $table->enum('type',['payment','payout','refund']);
            $table->enum('status',['pending ','completed ','failed ']);
            $table->enum('gateway',['ShamCash','Stripe']);
            $table->string('gateway_transaction_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transictions');
    }
};
