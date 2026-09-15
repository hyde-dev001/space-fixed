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
        Schema::create('shop_owner_subscription_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')
                ->constrained('shop_owner_subscription_payments')
                ->restrictOnDelete();
            $table->foreignId('subscription_id')
                ->constrained('shop_owner_subscriptions')
                ->restrictOnDelete();
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('super_admins')
                ->nullOnDelete();
            $table->uuid('local_reference')->unique();
            $table->string('provider_refund_id')->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('business_reason', 500);
            $table->string('provider_reason', 32);
            $table->string('status', 24)->default('pending');
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('initiated_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status'], 'subscription_refunds_payment_status_idx');
            $table->index(['subscription_id', 'status'], 'subscription_refunds_subscription_status_idx');
            $table->index('status', 'subscription_refunds_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_owner_subscription_refunds');
    }
};
