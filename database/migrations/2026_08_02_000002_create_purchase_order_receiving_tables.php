<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->boolean('is_historical')->default(false)->after('status');
            $table->enum('status', ['draft', 'sent', 'confirmed', 'in_transit', 'partially_received', 'delivered', 'completed', 'cancelled'])
                ->default('draft')->change();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('requested_size', 20)->nullable();
            $table->string('requested_color', 100)->nullable();
            $table->unsignedInteger('ordered_quantity');
            $table->decimal('unit_cost', 10, 2);
            $table->decimal('line_total', 12, 2);
            $table->unsignedInteger('quantity_multiplier')->default(1);
            $table->json('eligible_size_ids')->nullable();
            $table->string('source', 20)->default('manual');
            $table->timestamps();

            $table->index('purchase_request_id');
        });

        Schema::create('purchase_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_owner_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('posted');
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'idempotency_key']);
            $table->index(['shop_owner_id', 'received_at']);
        });

        Schema::create('purchase_order_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('received_quantity');
            $table->unsignedInteger('defective_quantity')->default(0);
            $table->unsignedInteger('accepted_quantity');
            $table->json('inventory_effects')->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_receipt_id', 'purchase_order_item_id'], 'po_receipt_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receipt_items');
        Schema::dropIfExists('purchase_order_receipts');
        Schema::dropIfExists('purchase_order_items');

        DB::table('purchase_orders')->where('status', 'partially_received')->update(['status' => 'in_transit']);

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->enum('status', ['draft', 'sent', 'confirmed', 'in_transit', 'delivered', 'completed', 'cancelled'])
                ->default('draft')->change();
            $table->dropColumn('is_historical');
        });
    }
};
