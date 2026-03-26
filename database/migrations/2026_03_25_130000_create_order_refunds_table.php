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
        if (!Schema::hasTable('order_refunds')) {
            Schema::create('order_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('shop_owner_id')->nullable()->constrained('shop_owners')->nullOnDelete();
                $table->enum('flow_type', ['cancel_auto', 'request_approval'])->default('cancel_auto');
                $table->enum('status', ['requested', 'pending_approval', 'processing', 'succeeded', 'failed', 'rejected'])->default('requested');
                $table->string('payment_gateway')->default('paymongo');
                $table->string('paymongo_payment_id')->nullable();
                $table->string('paymongo_refund_id')->nullable()->unique();
                $table->decimal('amount', 10, 2)->default(0);
                $table->string('currency', 3)->default('PHP');
                $table->string('reason_code')->nullable();
                $table->text('reason_note')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->string('idempotency_key')->unique();
                $table->text('failure_reason')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['order_id', 'flow_type']);
                $table->index(['order_id', 'status']);
                $table->index(['paymongo_payment_id']);
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'paymongo_refund_id')) {
                $table->string('paymongo_refund_id')->nullable()->after('paymongo_payment_id');
            }

            if (!Schema::hasColumn('orders', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('paid_at');
            }

            if (!Schema::hasColumn('orders', 'refund_reason')) {
                $table->text('refund_reason')->nullable()->after('payment_failure_reason');
            }

            if (!Schema::hasColumn('orders', 'refund_note')) {
                $table->text('refund_note')->nullable()->after('refund_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_refunds')) {
            Schema::dropIfExists('order_refunds');
        }

        Schema::table('orders', function (Blueprint $table) {
            $columns = ['paymongo_refund_id', 'refunded_at', 'refund_reason', 'refund_note'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
