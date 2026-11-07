<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('showroom_product_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('slot_key', 80);
            $table->timestamps();
            $table->unique(['shop_owner_id', 'product_id']);
            $table->unique(['shop_owner_id', 'slot_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showroom_product_placements');
    }
};
