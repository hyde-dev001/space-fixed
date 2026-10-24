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
        Schema::create('retail_warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retail_warranty_issuance_id')->constrained()->restrictOnDelete();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('policy_snapshot');
            $table->json('item_snapshot');
            $table->unsignedInteger('original_covered_quantity');
            $table->unsignedInteger('refunded_quantity')->default(0);
            $table->dateTime('warranty_start_date');
            $table->dateTime('warranty_expiration_date');
            $table->string('status', 16)->default('active');
            $table->dateTime('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->string('void_actor_type', 32)->nullable();
            $table->unsignedBigInteger('void_actor_id')->nullable();
            $table->index(['shop_owner_id', 'status', 'warranty_expiration_date'], 'retail_warranty_expiry_index');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retail_warranties');
    }
};
