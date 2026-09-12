<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
            $table->foreignId('replacement_for_adjustment_id')->nullable()
                ->after('purchase_order_item_id')
                ->constrained('supplier_adjustments')
                ->restrictOnDelete();
        });

        Schema::table('finance_expense_settlements', function (Blueprint $table): void {
            $table->foreignId('supplier_adjustment_id')->nullable()
                ->after('expense_id')
                ->constrained('supplier_adjustments')
                ->restrictOnDelete();
            $table->text('notes')->nullable()->after('source_reference');

            $table->index(
                ['supplier_adjustment_id', 'entry_type'],
                'finance_expense_settlements_adjustment_entry_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('finance_expense_settlements', function (Blueprint $table): void {
            $table->dropIndex('finance_expense_settlements_adjustment_entry_index');
            $table->dropConstrainedForeignId('supplier_adjustment_id');
            $table->dropColumn('notes');
        });

        Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replacement_for_adjustment_id');
        });
    }
};
