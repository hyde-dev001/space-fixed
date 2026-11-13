<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('shop_id')->constrained('users')->nullOnDelete();
            $table->foreignId('procurement_receipt_id')->nullable()->unique()->after('purchase_order_id')
                ->constrained('purchase_order_receipts')->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('purchase_order_receipt_item_id')->nullable()->unique()->after('reference_id')
                ->constrained('purchase_order_receipt_items')->nullOnDelete();
            $table->foreignId('reversal_of_stock_movement_id')->nullable()->unique()->after('purchase_order_receipt_item_id')
                ->constrained('stock_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
			$table->dropUnique(['reversal_of_stock_movement_id']);
			$table->dropUnique(['purchase_order_receipt_item_id']);
            $table->dropConstrainedForeignId('reversal_of_stock_movement_id');
            $table->dropConstrainedForeignId('purchase_order_receipt_item_id');
        });

        Schema::table('finance_expenses', function (Blueprint $table) {
			$table->dropUnique(['procurement_receipt_id']);
            $table->dropConstrainedForeignId('procurement_receipt_id');
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
