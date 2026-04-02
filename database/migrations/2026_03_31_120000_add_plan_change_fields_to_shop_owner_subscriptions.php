<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')) {
                $table->foreignId('pending_premium_plan_id')
                    ->nullable()
                    ->after('premium_plan_id')
                    ->constrained('premium_plans')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')) {
                $table->timestamp('pending_plan_effective_at')
                    ->nullable()
                    ->after('pending_premium_plan_id');
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'replaces_subscription_id')) {
                $table->foreignId('replaces_subscription_id')
                    ->nullable()
                    ->after('renewal_of_subscription_id')
                    ->constrained('shop_owner_subscriptions')
                    ->nullOnDelete();
            }
        });

        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->index('pending_plan_effective_at', 'shop_owner_subscriptions_pending_plan_at_idx');
            $table->index('replaces_subscription_id', 'shop_owner_subscriptions_replaces_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->dropIndex('shop_owner_subscriptions_pending_plan_at_idx');
            $table->dropIndex('shop_owner_subscriptions_replaces_idx');

            if (Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')) {
                $table->dropConstrainedForeignId('pending_premium_plan_id');
            }

            if (Schema::hasColumn('shop_owner_subscriptions', 'replaces_subscription_id')) {
                $table->dropConstrainedForeignId('replaces_subscription_id');
            }

            if (Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')) {
                $table->dropColumn('pending_plan_effective_at');
            }
        });
    }
};