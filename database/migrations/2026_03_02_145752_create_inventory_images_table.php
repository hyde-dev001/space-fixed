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
        Schema::create('inventory_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->onDelete('cascade');
            $table->foreignId('inventory_color_variant_id')->nullable()->constrained('inventory_color_variants')->onDelete('cascade');
            $table->string('image_path');
            $table->boolean('is_thumbnail')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index('inventory_item_id');
            $table->index('inventory_color_variant_id');
            $table->index('is_thumbnail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_images');
    }
};
