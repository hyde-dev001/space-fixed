<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table): void {
            $table->string('receipt_reference', 32)->nullable()->after('status');
            $table->unique(['shop_owner_id', 'receipt_reference'], 'po_receipts_shop_reference_unique');
        });

        Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable()->after('purchase_order_item_id');
            $table->string('payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unsignedInteger('replacement_attempt')->default(0)->after('replacement_for_adjustment_id');
            $table->dropUnique('po_receipt_item_unique');
            $table->unique(
                ['purchase_order_receipt_id', 'purchase_order_item_id', 'replacement_for_adjustment_id', 'replacement_attempt'],
                'po_receipt_item_line_unique'
            );
            $table->index(['purchase_order_receipt_id', 'idempotency_key'], 'po_receipt_item_idempotency_index');
        });

        Schema::create('shop_procurement_receipt_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->unsignedSmallInteger('receipt_year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['shop_owner_id', 'receipt_year'], 'shop_procurement_receipt_year_unique');
        });

        Schema::table('supplier_adjustments', function (Blueprint $table): void {
            $table->string('replacement_status', 32)->nullable()->after('resolution');
            $table->string('return_status', 32)->nullable()->after('replacement_status');
            $table->unsignedInteger('short_fulfillment_quantity')->default(0)->after('reported_quantity');
            $table->text('decline_reason')->nullable()->after('procurement_notes');
            $table->string('supplier_reference', 160)->nullable()->after('decline_reason');
            $table->text('return_notes')->nullable()->after('supplier_reference');
            $table->index(['shop_owner_id', 'replacement_status'], 'supplier_adjustments_replacement_status_index');
            $table->index(['shop_owner_id', 'return_status'], 'supplier_adjustments_return_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_adjustments', function (Blueprint $table): void {
            $table->dropIndex('supplier_adjustments_replacement_status_index');
            $table->dropIndex('supplier_adjustments_return_status_index');
            $table->dropColumn([
                'replacement_status',
                'return_status',
                'short_fulfillment_quantity',
                'decline_reason',
                'supplier_reference',
                'return_notes',
            ]);
        });

        Schema::dropIfExists('shop_procurement_receipt_sequences');

        Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
            $table->dropIndex('po_receipt_item_idempotency_index');
            $table->dropUnique('po_receipt_item_line_unique');
            $table->unique(['purchase_order_receipt_id', 'purchase_order_item_id'], 'po_receipt_item_unique');
            $table->dropColumn(['idempotency_key', 'payload_hash', 'replacement_attempt']);
        });

        Schema::table('purchase_order_receipts', function (Blueprint $table): void {
            $table->dropUnique('po_receipts_shop_reference_unique');
            $table->dropColumn('receipt_reference');
        });
    }
};
