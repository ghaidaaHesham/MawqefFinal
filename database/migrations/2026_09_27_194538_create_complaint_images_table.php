<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_images', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('title')->nullable();
            $table->morphs('imageable'); // imageable_type, imageable_id
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_images');
    }
};