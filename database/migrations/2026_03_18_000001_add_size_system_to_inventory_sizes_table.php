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
        Schema::table('inventory_sizes', function (Blueprint $table) {
            $table->string('size_system', 10)->default('US')->after('size');

            $table->dropUnique('unique_inventory_size');
            $table->unique(['inventory_item_id', 'size', 'size_system'], 'unique_inventory_size_system');
            $table->index(['inventory_item_id', 'size_system'], 'inventory_sizes_item_system_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_sizes', function (Blueprint $table) {
            $table->dropUnique('unique_inventory_size_system');
            $table->dropIndex('inventory_sizes_item_system_index');
            $table->dropColumn('size_system');

            $table->unique(['inventory_item_id', 'size'], 'unique_inventory_size');
        });
    }
};
