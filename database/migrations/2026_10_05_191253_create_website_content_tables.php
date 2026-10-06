<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->string('website_url', 2048)->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->index(['is_active', 'sort_order']);
            $table->timestamps();
        });
        Schema::create('partner_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unique(['partner_id', 'locale']);
            $table->timestamps();
        });
        Schema::create('setting_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('organization_name', 160);
            $table->text('address')->nullable();
            $table->string('seo_title', 200)->nullable();
            $table->text('seo_description')->nullable();
            $table->unique(['setting_id', 'locale']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_translations');
        Schema::dropIfExists('partner_translations');
        Schema::dropIfExists('partners');
    }
};
