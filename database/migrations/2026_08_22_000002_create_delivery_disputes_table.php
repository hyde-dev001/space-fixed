<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_disputes')) {
            return;
        }

        Schema::create('delivery_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_refund_id')->nullable()->constrained('order_refunds')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('shipment_leg_id')->nullable()->constrained('shipment_legs')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('investigated_at')->nullable();
            $table->string('investigated_by_type')->nullable();
            $table->unsignedBigInteger('investigated_by_id')->nullable();
            $table->string('resolution')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('resolved_by_type')->nullable();
            $table->unsignedBigInteger('resolved_by_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'status']);
            $table->index(['order_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_disputes');
    }
};
