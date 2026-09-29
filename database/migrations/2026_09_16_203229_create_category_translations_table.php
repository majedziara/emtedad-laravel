<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->unique(['category_id', 'locale']);
            $table->unique(['locale', 'slug']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_translations');
    }
};
