<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_refund_items')) {
            return;
        }

        Schema::create('pos_refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_refund_id')->constrained('pos_refunds')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('requested_qty');
            $table->unsignedInteger('approved_qty')->nullable();
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('line_amount', 12, 2);
            $table->enum('inspection_disposition', ['pending', 'resellable', 'damaged'])->default('pending');
            $table->enum('inventory_action', ['pending', 'restock', 'write_off'])->default('pending');
            $table->timestamp('inventory_applied_at')->nullable();
            $table->timestamps();

            $table->index(['order_item_id']);
            $table->index(['pos_refund_id', 'inventory_action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_refund_items');
    }
};
