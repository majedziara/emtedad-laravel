<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_section_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_section_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('title', 200)->nullable();
            $table->text('subtitle')->nullable();
            $table->longText('body')->nullable();
            $table->string('button_label', 100)->nullable();
            $table->string('button_url')->nullable();
            $table->json('items')->nullable();
            $table->unique(['content_section_id', 'locale']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_section_translations');
    }
};
