<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('humanitarian_case_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('title', 200);
            $table->string('slug', 180);
            $table->text('summary')->nullable();
            $table->longText('story');
            $table->string('location', 180)->nullable();
            $table->string('meta_title', 200)->nullable();
            $table->text('meta_description')->nullable();
            $table->unique(['humanitarian_case_id', 'locale']);
            $table->unique(['locale', 'slug']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_translations');
    }
};
