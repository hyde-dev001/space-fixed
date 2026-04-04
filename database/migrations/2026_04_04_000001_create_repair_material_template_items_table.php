<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_material_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('template_type');
            $table->unsignedBigInteger('template_id');
            $table->decimal('default_quantity', 10, 2);
            $table->boolean('is_critical')->default(false);
            $table->decimal('tolerance_percent', 5, 2)->default(20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['template_type', 'template_id']);
            $table->unique(['template_type', 'template_id', 'inventory_item_id'], 'repair_material_template_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_material_template_items');
    }
};
