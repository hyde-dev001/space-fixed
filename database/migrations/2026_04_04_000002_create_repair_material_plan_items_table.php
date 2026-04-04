<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_material_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_request_id')->constrained('repair_requests')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('planned_quantity', 10, 2);
            $table->decimal('actual_quantity', 10, 2)->default(0);
            $table->boolean('is_critical')->default(false);
            $table->decimal('tolerance_percent', 5, 2)->default(20);
            $table->enum('variance_status', ['within_tolerance', 'exceeded_with_note', 'escalated'])->default('within_tolerance');
            $table->text('variance_note')->nullable();
            $table->timestamps();

            $table->unique(['repair_request_id', 'inventory_item_id'], 'repair_material_plan_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_material_plan_items');
    }
};
