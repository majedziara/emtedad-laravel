<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('humanitarian_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('image_path')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->index(['humanitarian_case_id', 'status', 'published_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_updates');
    }
};
