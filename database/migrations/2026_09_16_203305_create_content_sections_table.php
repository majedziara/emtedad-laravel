<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('page', 80)->default('home');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->index(['page', 'is_active', 'sort_order']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_sections');
    }
};
