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
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_of_subscription_id')) {
                $table->foreignId('renewal_of_subscription_id')
                    ->nullable()
                    ->after('paid_amount')
                    ->constrained('shop_owner_subscriptions')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_due_at')) {
                $table->timestamp('renewal_due_at')->nullable()->after('renewal_of_subscription_id');
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_retry_count')) {
                $table->unsignedInteger('renewal_retry_count')->default(0)->after('renewal_due_at');
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_last_attempt_at')) {
                $table->timestamp('renewal_last_attempt_at')->nullable()->after('renewal_retry_count');
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_next_attempt_at')) {
                $table->timestamp('renewal_next_attempt_at')->nullable()->after('renewal_last_attempt_at');
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_checkout_session_id')) {
                $table->string('renewal_checkout_session_id')->nullable()->after('renewal_next_attempt_at');
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_checkout_url')) {
                $table->text('renewal_checkout_url')->nullable()->after('renewal_checkout_session_id');
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_checkout_url_expires_at')) {
                $table->timestamp('renewal_checkout_url_expires_at')->nullable()->after('renewal_checkout_url');
            }

            if (!Schema::hasColumn('shop_owner_subscriptions', 'renewal_next_attempt_at')) {
                // no-op guard for static analysis paths
            }
        });

        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->index('renewal_due_at', 'shop_owner_subscriptions_renewal_due_idx');
            $table->index('renewal_next_attempt_at', 'shop_owner_subscriptions_renewal_next_attempt_idx');
            $table->index('renewal_of_subscription_id', 'shop_owner_subscriptions_renewal_of_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->dropIndex('shop_owner_subscriptions_renewal_due_idx');
            $table->dropIndex('shop_owner_subscriptions_renewal_next_attempt_idx');
            $table->dropIndex('shop_owner_subscriptions_renewal_of_idx');

            if (Schema::hasColumn('shop_owner_subscriptions', 'renewal_of_subscription_id')) {
                $table->dropConstrainedForeignId('renewal_of_subscription_id');
            }

            $table->dropColumn([
                'renewal_due_at',
                'renewal_retry_count',
                'renewal_last_attempt_at',
                'renewal_next_attempt_at',
                'renewal_checkout_session_id',
                'renewal_checkout_url',
                'renewal_checkout_url_expires_at',
            ]);
        });
    }
};
