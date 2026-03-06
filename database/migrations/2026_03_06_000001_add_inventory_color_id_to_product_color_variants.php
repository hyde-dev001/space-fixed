<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds inventory_color_id to product_color_variants so each product
     * colour can be traced back to the inventory colour variant that sourced it.
     */
    public function up(): void
    {
        Schema::table('product_color_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_color_id')
                  ->nullable()
                  ->after('id')
                  ->comment('FK to inventory_color_variants — null for manually-added colours');

            $table->foreign('inventory_color_id')
                  ->references('id')
                  ->on('inventory_color_variants')
                  ->nullOnDelete();

            $table->index('inventory_color_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_color_variants', function (Blueprint $table) {
            $table->dropForeign(['inventory_color_id']);
            $table->dropIndex(['inventory_color_id']);
            $table->dropColumn('inventory_color_id');
        });
    }
};
