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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_owner_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('compare_at_price', 10, 2)->nullable(); // Original price for discounts
            $table->string('brand')->nullable();
            $table->string('category')->default('shoes'); // shoes, accessories, etc.
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->string('main_image')->nullable();
            $table->json('additional_images')->nullable(); // Array of image paths
            $table->json('sizes_available')->nullable(); // ["7", "8", "9", "10"]
            $table->json('colors_available')->nullable(); // ["Black", "White", "Red"]
            $table->string('sku')->nullable(); // Stock keeping unit
            $table->decimal('weight', 8, 2)->nullable(); // For shipping calculations
            $table->integer('views_count')->default(0);
            $table->integer('sales_count')->default(0);
            $table->timestamps();
            $table->softDeletes(); // For soft deletion

            // Indexes
            $table->index('shop_owner_id');
            $table->index('slug');
            $table->index('category');
            $table->index('is_active');
            $table->index(['shop_owner_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
