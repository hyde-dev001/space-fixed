<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureReceiptReference();
        $this->ensureReceiptItems();
        $this->ensureReceiptSequence();
        $this->ensureSupplierAdjustments();
    }

    public function down(): void
    {
        if (Schema::hasTable('supplier_adjustments')) {
            foreach ([
                'supplier_adjustments_replacement_status_index',
                'supplier_adjustments_return_status_index',
            ] as $index) {
                if (Schema::hasIndex('supplier_adjustments', $index)) {
                    Schema::table('supplier_adjustments', function (Blueprint $table) use ($index): void {
                        $table->dropIndex($index);
                    });
                }
            }

            Schema::table('supplier_adjustments', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('supplier_adjustments', 'replacement_status') ? 'replacement_status' : null,
                    Schema::hasColumn('supplier_adjustments', 'return_status') ? 'return_status' : null,
                    Schema::hasColumn('supplier_adjustments', 'short_fulfillment_quantity') ? 'short_fulfillment_quantity' : null,
                    Schema::hasColumn('supplier_adjustments', 'decline_reason') ? 'decline_reason' : null,
                    Schema::hasColumn('supplier_adjustments', 'supplier_reference') ? 'supplier_reference' : null,
                    Schema::hasColumn('supplier_adjustments', 'return_notes') ? 'return_notes' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('shop_procurement_receipt_sequences');

        if (Schema::hasTable('purchase_order_receipt_items')) {
            if (Schema::hasIndex('purchase_order_receipt_items', 'po_receipt_item_line_unique')) {
                Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                    $table->dropUnique('po_receipt_item_line_unique');
                });
            }
            if (Schema::hasIndex('purchase_order_receipt_items', 'po_receipt_item_idempotency_index')) {
                Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                    $table->dropIndex('po_receipt_item_idempotency_index');
                });
            }

            Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('purchase_order_receipt_items', 'idempotency_key') ? 'idempotency_key' : null,
                    Schema::hasColumn('purchase_order_receipt_items', 'payload_hash') ? 'payload_hash' : null,
                    Schema::hasColumn('purchase_order_receipt_items', 'replacement_attempt') ? 'replacement_attempt' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });

            if (! Schema::hasIndex('purchase_order_receipt_items', 'po_receipt_item_unique')) {
                Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                    $table->unique(
                        ['purchase_order_receipt_id', 'purchase_order_item_id'],
                        'po_receipt_item_unique',
                    );
                });
            }
        }

        if (Schema::hasTable('purchase_order_receipts')) {
            if (Schema::hasIndex('purchase_order_receipts', 'po_receipts_shop_reference_unique')) {
                Schema::table('purchase_order_receipts', function (Blueprint $table): void {
                    $table->dropUnique('po_receipts_shop_reference_unique');
                });
            }
            if (Schema::hasColumn('purchase_order_receipts', 'receipt_reference')) {
                Schema::table('purchase_order_receipts', function (Blueprint $table): void {
                    $table->dropColumn('receipt_reference');
                });
            }
        }
    }

    private function ensureReceiptReference(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_order_receipts', 'receipt_reference')) {
                $table->string('receipt_reference', 32)->nullable()->after('status');
            }
        });

        // Replace a pre-existing global receipt reference constraint with the
        // required shop-scoped constraint when a deployment was partially applied.
        foreach (collect(Schema::getIndexes('purchase_order_receipts'))
            ->filter(fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['receipt_reference'])
            ->pluck('name') as $index) {
            Schema::table('purchase_order_receipts', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index);
            });
        }

        if (! $this->hasIndex(
            'purchase_order_receipts',
            'po_receipts_shop_reference_unique',
            ['shop_owner_id', 'receipt_reference'],
            true,
        )) {
            Schema::table('purchase_order_receipts', function (Blueprint $table): void {
                $table->unique(
                    ['shop_owner_id', 'receipt_reference'],
                    'po_receipts_shop_reference_unique',
                );
            });
        }
    }

    private function ensureReceiptItems(): void
    {
        Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_order_receipt_items', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->after('purchase_order_item_id');
            }
            if (! Schema::hasColumn('purchase_order_receipt_items', 'payload_hash')) {
                $table->string('payload_hash', 64)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('purchase_order_receipt_items', 'replacement_attempt')) {
                $table->unsignedInteger('replacement_attempt')->default(0)->after('replacement_for_adjustment_id');
            }
        });

        if (Schema::hasIndex('purchase_order_receipt_items', 'po_receipt_item_unique')) {
            Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                $table->dropUnique('po_receipt_item_unique');
            });
        }

        if (! $this->hasIndex(
            'purchase_order_receipt_items',
            'po_receipt_item_line_unique',
            ['purchase_order_receipt_id', 'purchase_order_item_id', 'replacement_for_adjustment_id', 'replacement_attempt'],
            true,
        )) {
            Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                $table->unique(
                    ['purchase_order_receipt_id', 'purchase_order_item_id', 'replacement_for_adjustment_id', 'replacement_attempt'],
                    'po_receipt_item_line_unique',
                );
            });
        }

        if (! $this->hasIndex(
            'purchase_order_receipt_items',
            'po_receipt_item_idempotency_index',
            ['purchase_order_receipt_id', 'idempotency_key'],
        )) {
            Schema::table('purchase_order_receipt_items', function (Blueprint $table): void {
                $table->index(
                    ['purchase_order_receipt_id', 'idempotency_key'],
                    'po_receipt_item_idempotency_index',
                );
            });
        }
    }

    private function ensureReceiptSequence(): void
    {
        if (! Schema::hasTable('shop_procurement_receipt_sequences')) {
            Schema::create('shop_procurement_receipt_sequences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
                $table->unsignedSmallInteger('receipt_year');
                $table->unsignedInteger('next_number')->default(1);
                $table->timestamps();

                $table->unique(['shop_owner_id', 'receipt_year'], 'shop_procurement_receipt_year_unique');
            });

            return;
        }

        if (! $this->hasIndex(
            'shop_procurement_receipt_sequences',
            'shop_procurement_receipt_year_unique',
            ['shop_owner_id', 'receipt_year'],
            true,
        )) {
            Schema::table('shop_procurement_receipt_sequences', function (Blueprint $table): void {
                $table->unique(['shop_owner_id', 'receipt_year'], 'shop_procurement_receipt_year_unique');
            });
        }
    }

    private function ensureSupplierAdjustments(): void
    {
        Schema::table('supplier_adjustments', function (Blueprint $table): void {
            if (! Schema::hasColumn('supplier_adjustments', 'replacement_status')) {
                $table->string('replacement_status', 32)->nullable()->after('resolution');
            }
            if (! Schema::hasColumn('supplier_adjustments', 'return_status')) {
                $table->string('return_status', 32)->nullable()->after('replacement_status');
            }
            if (! Schema::hasColumn('supplier_adjustments', 'short_fulfillment_quantity')) {
                $table->unsignedInteger('short_fulfillment_quantity')->default(0)->after('reported_quantity');
            }
            if (! Schema::hasColumn('supplier_adjustments', 'decline_reason')) {
                $table->text('decline_reason')->nullable()->after('procurement_notes');
            }
            if (! Schema::hasColumn('supplier_adjustments', 'supplier_reference')) {
                $table->string('supplier_reference', 160)->nullable()->after('decline_reason');
            }
            if (! Schema::hasColumn('supplier_adjustments', 'return_notes')) {
                $table->text('return_notes')->nullable()->after('supplier_reference');
            }
        });

        foreach ([
            ['supplier_adjustments_replacement_status_index', ['shop_owner_id', 'replacement_status']],
            ['supplier_adjustments_return_status_index', ['shop_owner_id', 'return_status']],
        ] as [$name, $columns]) {
            if (! $this->hasIndex('supplier_adjustments', $name, $columns)) {
                Schema::table('supplier_adjustments', function (Blueprint $table) use ($name, $columns): void {
                    $table->index($columns, $name);
                });
            }
        }
    }

    private function hasIndex(string $table, string $name, array $columns, ?bool $unique = null): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            function (array $index) use ($name, $columns, $unique): bool {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }

                if (($index['columns'] ?? []) !== $columns) {
                    return false;
                }

                return $unique === null || (bool) ($index['unique'] ?? false) === $unique;
            },
        );
    }
};
