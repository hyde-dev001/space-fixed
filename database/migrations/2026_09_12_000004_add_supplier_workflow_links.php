<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RECEIPT_ITEM_REPLACEMENT_FOREIGN_KEY = 'po_receipt_items_replacement_adj_fk';

    private const SETTLEMENT_ADJUSTMENT_FOREIGN_KEY = 'finance_settlements_supplier_adj_fk';

    public function up(): void
    {
        if (! Schema::hasColumn('purchase_order_receipt_items', 'replacement_for_adjustment_id')) {
            Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                $table->foreignId('replacement_for_adjustment_id')->nullable()
                    ->after('purchase_order_item_id');
            });
        }

        if (! $this->hasForeignKey('purchase_order_receipt_items', 'replacement_for_adjustment_id')) {
            Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                $table->foreign('replacement_for_adjustment_id', self::RECEIPT_ITEM_REPLACEMENT_FOREIGN_KEY)
                    ->references('id')
                    ->on('supplier_adjustments')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('finance_expense_settlements', 'supplier_adjustment_id')) {
            Schema::table('finance_expense_settlements', function (Blueprint $table): void {
                $table->foreignId('supplier_adjustment_id')->nullable()
                    ->after('expense_id');
            });
        }

        if (! $this->hasForeignKey('finance_expense_settlements', 'supplier_adjustment_id')) {
            Schema::table('finance_expense_settlements', function (Blueprint $table): void {
                $table->foreign('supplier_adjustment_id', self::SETTLEMENT_ADJUSTMENT_FOREIGN_KEY)
                    ->references('id')
                    ->on('supplier_adjustments')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('finance_expense_settlements', 'notes')) {
            Schema::table('finance_expense_settlements', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('source_reference');
            });
        }

        if (! Schema::hasIndex(
            'finance_expense_settlements',
            'finance_expense_settlements_adjustment_entry_index'
        )) {
            Schema::table('finance_expense_settlements', function (Blueprint $table): void {
                $table->index(
                    ['supplier_adjustment_id', 'entry_type'],
                    'finance_expense_settlements_adjustment_entry_index'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('finance_expense_settlements', function (Blueprint $table): void {
            $table->dropIndex('finance_expense_settlements_adjustment_entry_index');
            $table->dropForeign(self::SETTLEMENT_ADJUSTMENT_FOREIGN_KEY);
            $table->dropColumn('notes');
            $table->dropColumn('supplier_adjustment_id');
        });

        Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
            $table->dropForeign(self::RECEIPT_ITEM_REPLACEMENT_FOREIGN_KEY);
            $table->dropColumn('replacement_for_adjustment_id');
        });
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'], true)) {
                return true;
            }
        }

        return false;
    }
};
