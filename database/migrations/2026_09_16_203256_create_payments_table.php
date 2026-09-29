<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_order_id', 160)->nullable();
            $table->string('provider_transaction_id', 160)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 24)->default('pending');
            $table->string('failure_code', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unique(['provider', 'provider_order_id']);
            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['donation_id', 'status']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
