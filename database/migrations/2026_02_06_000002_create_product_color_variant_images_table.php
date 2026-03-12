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
        Schema::create('product_color_variant_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_color_variant_id')->constrained('product_color_variants')->onDelete('cascade');
            $table->string('image_path'); // Path to the image file
            $table->string('alt_text')->nullable(); // Alt text for accessibility
            $table->boolean('is_thumbnail')->default(false); // First image is thumbnail
            $table->integer('sort_order')->default(0); // Image display order
            $table->string('image_type')->default('product'); // product, detail, lifestyle, etc.
            $table->timestamps();
            
            // Index for performance (with custom shorter names)
            $table->index(['product_color_variant_id', 'sort_order'], 'pcvi_variant_sort_idx');
            $table->index(['product_color_variant_id', 'is_thumbnail'], 'pcvi_variant_thumb_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_color_variant_images');
    }
};