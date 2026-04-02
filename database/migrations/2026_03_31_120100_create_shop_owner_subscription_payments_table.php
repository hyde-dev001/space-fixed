<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_owner_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('shop_owner_subscriptions')->nullOnDelete();
            $table->foreignId('source_subscription_id')->nullable()->constrained('shop_owner_subscriptions')->nullOnDelete();
            $table->foreignId('from_premium_plan_id')->nullable()->constrained('premium_plans')->nullOnDelete();
            $table->foreignId('to_premium_plan_id')->nullable()->constrained('premium_plans')->nullOnDelete();
            $table->string('payment_type', 24)->default('new_subscription');
            $table->string('gateway', 24)->default('paymongo');
            $table->string('currency', 3)->default('PHP');
            $table->string('paymongo_session_id')->nullable();
            $table->string('paymongo_payment_id')->nullable();
            $table->decimal('plan_price', 10, 2)->default(0);
            $table->decimal('proration_credit', 10, 2)->default(0);
            $table->decimal('amount_due', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->nullable();
            $table->string('status', 24)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'payment_type'], 'shop_owner_sub_payments_owner_type_idx');
            $table->index('status', 'shop_owner_sub_payments_status_idx');
            $table->index('paymongo_session_id', 'shop_owner_sub_payments_session_idx');
            $table->index('paymongo_payment_id', 'shop_owner_sub_payments_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_owner_subscription_payments');
    }
};