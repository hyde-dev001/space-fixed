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
        Schema::create('product_color_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('color_name'); // e.g., "Red", "Blue", "Forest Green"
            $table->string('color_code')->nullable(); // e.g., "#FF0000", "#0000FF"
            $table->string('sku_prefix')->nullable(); // e.g., "SHOE-RED"
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0); // For color display ordering
            $table->timestamps();
            
            // Ensure unique color per product
            $table->unique(['product_id', 'color_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_color_variants');
    }
};