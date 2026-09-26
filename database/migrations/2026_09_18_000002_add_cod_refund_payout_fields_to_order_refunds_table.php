<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_refunds', 'refund_destination_type')) {
                $table->string('refund_destination_type', 32)->nullable()->after('requested_refund_method');
            }

            if (! Schema::hasColumn('order_refunds', 'refund_destination')) {
                $table->text('refund_destination')->nullable()->after('refund_destination_type');
            }

            if (! Schema::hasColumn('order_refunds', 'refund_provider')) {
                $table->string('refund_provider', 32)->nullable()->after('refund_destination');
            }

            if (! Schema::hasColumn('order_refunds', 'payout_status')) {
                $table->string('payout_status', 32)->default('not_started')->after('refund_provider');
            }

            if (! Schema::hasColumn('order_refunds', 'payout_idempotency_key')) {
                $table->string('payout_idempotency_key')->nullable()->unique();
            }

            if (! Schema::hasColumn('order_refunds', 'provider_payout_id')) {
                $table->string('provider_payout_id')->nullable();
            }

            if (! Schema::hasColumn('order_refunds', 'provider_reference')) {
                $table->string('provider_reference')->nullable();
            }

            if (! Schema::hasColumn('order_refunds', 'payout_initiated_at')) {
                $table->timestamp('payout_initiated_at')->nullable();
            }

            if (! Schema::hasColumn('order_refunds', 'payout_succeeded_at')) {
                $table->timestamp('payout_succeeded_at')->nullable();
            }

            if (! Schema::hasColumn('order_refunds', 'payout_failed_at')) {
                $table->timestamp('payout_failed_at')->nullable();
            }

            if (! Schema::hasColumn('order_refunds', 'payout_reversed_at')) {
                $table->timestamp('payout_reversed_at')->nullable();
            }

            if (! Schema::hasColumn('order_refunds', 'payout_failure_code')) {
                $table->string('payout_failure_code', 64)->nullable();
            }

            if (! Schema::hasColumn('order_refunds', 'payout_failure_message')) {
                $table->text('payout_failure_message')->nullable();
            }

            $table->index(['refund_provider', 'payout_status'], 'order_refunds_payout_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_refunds', function (Blueprint $table): void {
            $table->dropIndex('order_refunds_payout_state_index');
            $table->dropUnique('order_refunds_payout_idempotency_key_unique');
            $table->dropColumn([
                'refund_destination_type',
                'refund_destination',
                'refund_provider',
                'payout_status',
                'payout_idempotency_key',
                'provider_payout_id',
                'provider_reference',
                'payout_initiated_at',
                'payout_succeeded_at',
                'payout_failed_at',
                'payout_reversed_at',
                'payout_failure_code',
                'payout_failure_message',
            ]);
        });
    }
};
