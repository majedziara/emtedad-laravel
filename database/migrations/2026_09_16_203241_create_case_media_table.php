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
        Schema::create('case_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('humanitarian_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('visibility', 16)->default('private');
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->index(['humanitarian_case_id', 'visibility', 'sort_order']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('case_media');
    }
};
