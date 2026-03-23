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
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'payment_link_created_at')) {
                $table->timestamp('payment_link_created_at')->nullable()->after('paymongo_payment_id');
            }

            if (!Schema::hasColumn('orders', 'payment_expires_at')) {
                $table->timestamp('payment_expires_at')->nullable()->after('payment_link_created_at');
            }

            if (!Schema::hasColumn('orders', 'payment_failed_at')) {
                $table->timestamp('payment_failed_at')->nullable()->after('payment_expires_at');
            }

            if (!Schema::hasColumn('orders', 'payment_failure_reason')) {
                $table->string('payment_failure_reason')->nullable()->after('payment_failed_at');
            }

            if (!Schema::hasColumn('orders', 'payment_expired_at')) {
                $table->timestamp('payment_expired_at')->nullable()->after('payment_failure_reason');
            }

            if (!Schema::hasColumn('orders', 'payment_released_at')) {
                $table->timestamp('payment_released_at')->nullable()->after('payment_expired_at');
            }
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('repair_requests', 'payment_expires_at')) {
                $table->timestamp('payment_expires_at')->nullable()->after('payment_link_created_at');
            }

            if (!Schema::hasColumn('repair_requests', 'payment_failed_at')) {
                $table->timestamp('payment_failed_at')->nullable()->after('payment_expires_at');
            }

            if (!Schema::hasColumn('repair_requests', 'payment_failure_reason')) {
                $table->string('payment_failure_reason')->nullable()->after('payment_failed_at');
            }

            if (!Schema::hasColumn('repair_requests', 'payment_expired_at')) {
                $table->timestamp('payment_expired_at')->nullable()->after('payment_failure_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'payment_link_created_at',
                'payment_expires_at',
                'payment_failed_at',
                'payment_failure_reason',
                'payment_expired_at',
                'payment_released_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            $columns = [
                'payment_expires_at',
                'payment_failed_at',
                'payment_failure_reason',
                'payment_expired_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('repair_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
