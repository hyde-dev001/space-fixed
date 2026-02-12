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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('size')->nullable(); // e.g., "7", "8", "9"
            $table->string('color')->nullable(); // e.g., "Black", "White", "Red"
            $table->string('image')->nullable(); // Variant-specific image (represents this color)
            $table->integer('quantity')->default(0); // Stock quantity for this specific variant
            $table->string('sku')->nullable(); // Variant-specific SKU
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ensure each product has unique size/color combinations
            $table->unique(['product_id', 'size', 'color'], 'unique_variant');
            
            // Indexes for faster queries
            $table->index('product_id');
            $table->index(['product_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
