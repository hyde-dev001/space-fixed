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
        Schema::table('order_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('order_refunds', 'shop_owner_status')) {
                $table->enum('shop_owner_status', ['pending', 'approved', 'rejected'])
                    ->default('pending')
                    ->after('status');
            }

            if (!Schema::hasColumn('order_refunds', 'shop_owner_approved_at')) {
                $table->timestamp('shop_owner_approved_at')
                    ->nullable()
                    ->after('shop_owner_status');
            }

            if (!Schema::hasColumn('order_refunds', 'shop_owner_approved_by')) {
                $table->foreignId('shop_owner_approved_by')
                    ->nullable()
                    ->after('shop_owner_approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('order_refunds', 'finance_status')) {
                $table->enum('finance_status', ['pending', 'approved', 'rejected'])
                    ->default('pending')
                    ->after('shop_owner_approved_by');
            }

            if (!Schema::hasColumn('order_refunds', 'finance_approved_at')) {
                $table->timestamp('finance_approved_at')
                    ->nullable()
                    ->after('finance_status');
            }

            if (!Schema::hasColumn('order_refunds', 'finance_approved_by')) {
                $table->foreignId('finance_approved_by')
                    ->nullable()
                    ->after('finance_approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('order_refunds', 'return_status')) {
                $table->enum('return_status', ['not_required', 'awaiting_approval', 'pending_customer_shipment', 'in_transit', 'received'])
                    ->default('awaiting_approval')
                    ->after('finance_approved_by');
            }

            if (!Schema::hasColumn('order_refunds', 'return_confirmed_at')) {
                $table->timestamp('return_confirmed_at')
                    ->nullable()
                    ->after('return_status');
            }

            if (!Schema::hasColumn('order_refunds', 'return_confirmed_by_staff_id')) {
                $table->foreignId('return_confirmed_by_staff_id')
                    ->nullable()
                    ->after('return_confirmed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('order_refunds', 'return_notes')) {
                $table->text('return_notes')
                    ->nullable()
                    ->after('return_confirmed_by_staff_id');
            }

            if (!Schema::hasColumn('order_refunds', 'customer_return_tracking_number')) {
                $table->string('customer_return_tracking_number')
                    ->nullable()
                    ->after('return_notes');
            }

            if (!Schema::hasColumn('order_refunds', 'customer_return_carrier')) {
                $table->string('customer_return_carrier')
                    ->nullable()
                    ->after('customer_return_tracking_number');
            }

            if (!Schema::hasColumn('order_refunds', 'customer_return_tracking_link')) {
                $table->string('customer_return_tracking_link')
                    ->nullable()
                    ->after('customer_return_carrier');
            }

            if (!Schema::hasColumn('order_refunds', 'customer_return_shipped_at')) {
                $table->timestamp('customer_return_shipped_at')
                    ->nullable()
                    ->after('customer_return_tracking_link');
            }

            if (!Schema::hasColumn('order_refunds', 'refund_executed_at')) {
                $table->timestamp('refund_executed_at')
                    ->nullable()
                    ->after('customer_return_shipped_at');
            }

            $table->index(['shop_owner_status', 'finance_status', 'return_status'], 'order_refunds_stage_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            if (Schema::hasColumn('order_refunds', 'shop_owner_approved_by')) {
                $table->dropConstrainedForeignId('shop_owner_approved_by');
            }

            if (Schema::hasColumn('order_refunds', 'finance_approved_by')) {
                $table->dropConstrainedForeignId('finance_approved_by');
            }

            if (Schema::hasColumn('order_refunds', 'return_confirmed_by_staff_id')) {
                $table->dropConstrainedForeignId('return_confirmed_by_staff_id');
            }

            try {
                $table->dropIndex('order_refunds_stage_idx');
            } catch (\Throwable $e) {
                // Ignore if index does not exist.
            }

            $columns = [
                'shop_owner_status',
                'shop_owner_approved_at',
                'finance_status',
                'finance_approved_at',
                'return_status',
                'return_confirmed_at',
                'return_notes',
                'customer_return_tracking_number',
                'customer_return_carrier',
                'customer_return_tracking_link',
                'customer_return_shipped_at',
                'refund_executed_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('order_refunds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
