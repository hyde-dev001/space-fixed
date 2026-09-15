<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->foreignId('purchase_order_receipt_item_id')
                ->constrained('purchase_order_receipt_items')
                ->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('issue_stage', 32);
            $table->unsignedInteger('reported_quantity');
            $table->decimal('unit_cost_snapshot', 18, 2);
            $table->string('reason_category', 64);
            $table->text('inventory_notes');
            $table->string('status', 32)->default('reported');
            $table->string('resolution', 32)->nullable();
            $table->text('procurement_notes')->nullable();
            $table->decimal('expected_refund_amount', 18, 2)->nullable();
            $table->decimal('supplier_reported_refund_amount', 18, 2)->nullable();
            $table->string('supplier_reported_refund_reference', 160)->nullable();
            $table->date('supplier_reported_refund_date')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_owner_id', 'idempotency_key'], 'supplier_adjustments_shop_key_unique');
            $table->index(['shop_owner_id', 'status'], 'supplier_adjustments_shop_status_index');
            $table->index(['purchase_order_receipt_item_id', 'issue_stage'], 'supplier_adjustments_item_stage_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_adjustments');
    }
};
