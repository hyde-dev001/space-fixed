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
        Schema::create('inventory_color_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->string('color_name', 100);
            $table->string('color_code', 7)->nullable();
            $table->integer('quantity')->default(0);
            $table->string('sku_suffix', 50)->nullable();
            $table->timestamps();
            
            $table->index('inventory_item_id');
            $table->unique(['inventory_item_id', 'color_name'], 'unique_inventory_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_color_variants');
    }
};
