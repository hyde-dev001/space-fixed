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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 50)->unique();
            $table->foreignId('pr_id')->nullable()->constrained('purchase_requests')->onDelete('set null');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('restrict');
            $table->string('product_name');
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->onDelete('set null');
            $table->integer('quantity');
            $table->decimal('unit_cost', 10, 2);
            $table->decimal('total_cost', 12, 2);
            $table->date('expected_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();
            $table->string('payment_terms')->default('Net 30');
            $table->enum('status', ['draft', 'sent', 'confirmed', 'in_transit', 'delivered', 'completed', 'cancelled'])->default('draft');
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('ordered_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('ordered_date');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('confirmed_date')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('delivered_date')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('completed_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['po_number']);
            $table->index(['pr_id']);
            $table->index(['shop_owner_id']);
            $table->index(['supplier_id']);
            $table->index(['status']);
            $table->index(['expected_delivery_date'], 'idx_po_expected_delivery');
            $table->index(['actual_delivery_date'], 'idx_po_actual_delivery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
