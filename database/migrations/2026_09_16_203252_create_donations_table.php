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
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('humanitarian_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('donor_name', 160)->nullable();
            $table->string('donor_email')->nullable();
            $table->string('donor_phone', 32)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('USD');
            $table->string('status', 24)->default('pending');
            $table->boolean('is_anonymous')->default(false);
            $table->text('message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->index(['humanitarian_case_id', 'status', 'currency', 'paid_at'], 'donations_case_totals_index');
            $table->index(['user_id', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
