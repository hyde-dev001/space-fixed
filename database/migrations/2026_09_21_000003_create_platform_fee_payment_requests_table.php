<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('requested_amount', 14, 2);
            $table->decimal('balance_snapshot', 14, 2);
            $table->decimal('credit_snapshot', 14, 2);
            $table->decimal('net_payable_snapshot', 14, 2);
            $table->string('status', 30)->default('pending_owner_approval');
            $table->foreignId('owner_approved_by_shop_owner_id')->nullable();
            $table->foreign('owner_approved_by_shop_owner_id', 'platform_fee_request_owner_fk')
                ->references('id')
                ->on('shop_owners')
                ->nullOnDelete();
            $table->timestamp('owner_approved_at')->nullable();
            $table->timestamp('owner_rejected_at')->nullable();
            $table->string('owner_decision_note', 500)->nullable();
            $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('platform_fee_payment_id')->nullable()->constrained('platform_fee_payments')->nullOnDelete();
            $table->string('stale_reason', 500)->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->string('idempotency_key', 150)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_payment_requests');
    }
};
