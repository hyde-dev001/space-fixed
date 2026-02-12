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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('order_number')->unique();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->string('payment_method')->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'refunded'])->default('pending');
            $table->string('tracking_number')->nullable();
            $table->string('shipping_carrier')->nullable();
            $table->date('shipped_date')->nullable();
            $table->text('notes')->nullable();
            
            // Invoice tracking
            $table->boolean('invoice_generated')->default(false);
            $table->unsignedBigInteger('invoice_id')->nullable();
            
            // Structured address fields
            $table->unsignedBigInteger('address_id')->nullable();
            $table->string('shipping_region')->nullable();
            $table->string('shipping_province')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_barangay')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_address_line')->nullable();
            
            $table->timestamps();

            $table->index('shop_owner_id');
            $table->index('customer_id');
            $table->index('order_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
