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
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            $table->integer('rating')->comment('Rating from 1-5');
            $table->text('comment');
            $table->json('images')->nullable()->comment('Review images uploaded by user');
            
            $table->boolean('is_verified_purchase')->default(true)->comment('Always true since only verified buyers can review');
            $table->boolean('is_approved')->default(true)->comment('Auto-approved, can be moderated later');
            
            $table->timestamps();
            
            // Indexes
            $table->index('product_id');
            $table->index('user_id');
            $table->index('order_id');
            $table->index('shop_owner_id');
            $table->index('is_approved');
            $table->index(['product_id', 'is_approved']);
            
            // Unique constraint: One review per user per product
            $table->unique(['product_id', 'user_id'], 'unique_product_user_review');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
