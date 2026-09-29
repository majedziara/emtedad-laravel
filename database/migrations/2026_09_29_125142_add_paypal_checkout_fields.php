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
        Schema::table('donations', function (Blueprint $table) {
            $table->char('guest_token_hash', 64)->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('environment', 16)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->uuid('capture_request_id')->nullable()->unique();
            $table->text('approval_url')->nullable();
            $table->timestamp('create_started_at')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->boolean('needs_review')->default(false)->index();
        });
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider_refund_id', 160)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['capture_request_id']);
            $table->dropIndex(['last_synced_at']);
            $table->dropIndex(['needs_review']);
            $table->dropColumn(['environment', 'request_fingerprint', 'capture_request_id', 'approval_url', 'create_started_at', 'last_synced_at', 'needs_review']);
        });
        Schema::table('donations', fn(Blueprint $table) => $table->dropColumn('guest_token_hash'));
    }
};
